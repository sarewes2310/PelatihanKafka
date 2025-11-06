import json
import os
import signal
import sys
import time
from typing import Any, Dict

import mysql.connector
from confluent_kafka import Consumer, KafkaError


# Kafka Configuration
BROKERS = os.getenv("KAFKA_BROKERS", "kafka:9092")
SOURCE_TOPIC = os.getenv("SOURCE_TOPIC", "enriched_orders")
GROUP_ID = os.getenv("SINK_GROUP_ID", "sink-mysql-workers")

# MySQL Configuration
MYSQL_HOST = os.getenv("MYSQL_HOST", "mysql_servers")
MYSQL_PORT = int(os.getenv("MYSQL_PORT", "3306"))
MYSQL_USER = os.getenv("MYSQL_USER", "root")
MYSQL_PASSWORD = os.getenv("MYSQL_PASSWORD", "root")
MYSQL_DATABASE = os.getenv("MYSQL_DATABASE", "app_consumer")
MYSQL_TABLE = os.getenv("MYSQL_TABLE", "orders")

running = True


def shutdown(signum, frame):
    """Handle graceful shutdown on SIGINT/SIGTERM"""
    global running
    running = False
    print("Received shutdown signal. Draining...")


signal.signal(signal.SIGINT, shutdown)
signal.signal(signal.SIGTERM, shutdown)


def build_consumer() -> Consumer:
    """Build and configure Kafka consumer"""
    return Consumer(
        {
            "bootstrap.servers": BROKERS,
            "group.id": GROUP_ID,
            "auto.offset.reset": "earliest",
            "enable.auto.commit": False,  # Manual commit for at-least-once delivery
        }
    )


def get_mysql_connection():
    """Create MySQL connection with retry logic"""
    max_retries = 5
    retry_delay = 5
    
    for attempt in range(max_retries):
        try:
            connection = mysql.connector.connect(
                host=MYSQL_HOST,
                port=MYSQL_PORT,
                user=MYSQL_USER,
                password=MYSQL_PASSWORD,
                database=MYSQL_DATABASE,
                autocommit=False,
            )
            print(f"Connected to MySQL at {MYSQL_HOST}:{MYSQL_PORT}/{MYSQL_DATABASE}")
            return connection
        except mysql.connector.Error as err:
            if attempt < max_retries - 1:
                print(f"MySQL connection attempt {attempt + 1} failed: {err}. Retrying in {retry_delay}s...")
                time.sleep(retry_delay)
            else:
                print(f"Failed to connect to MySQL after {max_retries} attempts: {err}")
                raise



def insert_order(connection, payload: Dict[str, Any]) -> bool:
    """Insert or update order data into MySQL"""
    insert_query = f"""
    INSERT INTO `{MYSQL_TABLE}` (id, nim, name, email, address)
    VALUES (%(id)s, %(order_id)s, %(quantity)s, %(unit_price)s, %(total_value)s, %(processed_at)s)
    ON DUPLICATE KEY UPDATE
        nim = VALUES(nim),
        name = VALUES(name),
        email = VALUES(email),
        address = VALUES(address)
    """
    
    try:
        cursor = connection.cursor()
        
        # Prepare data with defaults
        data = {
            "id": payload.get("id"),
            "nim": payload.get("nim", "unknown"),
            "name": payload.get("name", 1),
            "email": payload.get("email", 0.0),
            "address": payload.get("address", 0.0),
            "sync_at": int(time.time())
        }
        
        cursor.execute(insert_query, data)
        connection.commit()
        cursor.close()
        return True
    except mysql.connector.Error as err:
        print(f"Error inserting order: {err}")
        connection.rollback()
        return False


def main():
    """Main sink consumer loop"""
    consumer = build_consumer()
    consumer.subscribe([SOURCE_TOPIC])
    
    # Initialize MySQL connection
    mysql_conn = get_mysql_connection()
    
    print(f"Sink is consuming from '{SOURCE_TOPIC}' and writing to MySQL {MYSQL_HOST}/{MYSQL_DATABASE}.{MYSQL_TABLE}")
    
    message_count = 0
    error_count = 0
    
    try:
        while running:
            msg = consumer.poll(1.0)
            
            if msg is None:
                continue
            
            if msg.error():
                if msg.error().code() == KafkaError._PARTITION_EOF:
                    # End of partition - not an error
                    continue
                else:
                    print(f"Consumer error: {msg.error()}")
                    continue
            
            try:
                # Parse message
                payload = json.loads(msg.value().decode("utf-8"))
                
                # Insert into MySQL
                if insert_order(mysql_conn, payload):
                    message_count += 1
                    print(f"✓ Inserted order: {payload.get('order_id', 'N/A')} "
                          f"(total: {payload.get('total_value', 0.0)}) "
                          f"[{message_count} messages processed]")
                    
                    # Commit offset after successful insert
                    consumer.commit(msg)
                else:
                    error_count += 1
                    print(f"✗ Failed to insert order, will retry on next poll")
                    
            except json.JSONDecodeError as e:
                print(f"Skipping message: not valid JSON - {e}")
                consumer.commit(msg)  # Skip invalid messages
            except Exception as e:
                error_count += 1
                print(f"Error processing message: {e}")
                # Don't commit offset - will be retried
    
    except KeyboardInterrupt:
        print("\nInterrupted by user")
    finally:
        print(f"\nShutting down... (Processed: {message_count}, Errors: {error_count})")
        consumer.close()
        mysql_conn.close()
        print("Sink stopped cleanly.")


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        sys.exit(0)
    except Exception as e:
        print(f"Fatal error: {e}")
        sys.exit(1)
