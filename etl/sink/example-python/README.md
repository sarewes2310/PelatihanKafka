# Kafka MySQL Sink

Python-based Kafka consumer that sinks enriched event data into MySQL database.

## Overview

This sink connector consumes enriched order events from the `enriched_orders` Kafka topic and writes them to a MySQL database table. It demonstrates the final stage of the ETL pipeline:

**Pipeline Flow**: Producer → Kafka → Python ETL → Kafka → **Python Sink → MySQL**

## Features

- **Kafka Consumer**: Consumes from `enriched_orders` topic with manual offset management
- **MySQL Integration**: Writes to MySQL with automatic table creation
- **Idempotent Writes**: Uses `ON DUPLICATE KEY UPDATE` for upsert operations
- **Connection Retry**: Automatic retry logic for MySQL connections
- **Graceful Shutdown**: Handles SIGINT/SIGTERM signals properly
- **At-Least-Once Delivery**: Manual offset commits after successful database writes
- **Error Handling**: Comprehensive error handling with retry logic

## Architecture

```
┌─────────────────┐
│ Kafka Topic     │
│ enriched_orders │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Python Consumer │
│ (confluent-     │
│  kafka-python)  │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ MySQL Database  │
│ app_consumer    │
│ └─ orders table │
└─────────────────┘
```

## Requirements

- Python 3.11+
- confluent-kafka==2.4.0
- mysql-connector-python==8.2.0
- Docker & Docker Compose
- Running Kafka broker (kafka:9092)
- Running MySQL server (mysql_servers:3306)

## Configuration

Environment variables (all optional with defaults):

### Kafka Settings

- `KAFKA_BROKERS`: Kafka broker address (default: `kafka:9092`)
- `SOURCE_TOPIC`: Topic to consume from (default: `enriched_orders`)
- `SINK_GROUP_ID`: Consumer group ID (default: `sink-mysql-workers`)

### MySQL Settings

- `MYSQL_HOST`: MySQL hostname (default: `mysql_servers`)
- `MYSQL_PORT`: MySQL port (default: `3306`)
- `MYSQL_USER`: MySQL username (default: `root`)
- `MYSQL_PASSWORD`: MySQL password (default: `root`)
- `MYSQL_DATABASE`: Target database (default: `app_consumer`)
- `MYSQL_TABLE`: Target table (default: `orders`)

## Database Schema

The sink automatically creates the following table if it doesn't exist:

```sql
CREATE TABLE IF NOT EXISTS `orders` (
    `id` VARCHAR(36) PRIMARY KEY,
    `order_id` VARCHAR(255) NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `unit_price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `total_value` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `processed_at` BIGINT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_order_id` (`order_id`),
    INDEX `idx_processed_at` (`processed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Usage

### With Docker Compose (Recommended)

1. **Ensure network and dependencies are running**:

```bash
# Start Kafka broker
cd ../../../
docker-compose up -d

# Start MySQL and Laravel services
cd servers/
docker-compose up -d
```

2. **Start the sink**:

```bash
cd etl/sink/example-python/
docker-compose up -d
```

3. **View logs**:

```bash
docker-compose logs -f kafka-sink-mysql
```

4. **Stop the sink**:

```bash
docker-compose down
```

### Standalone (Development)

1. **Install dependencies**:

```bash
pip install -r requirements.txt
```

2. **Configure environment** (if running locally):

```bash
export KAFKA_BROKERS=localhost:9092
export MYSQL_HOST=localhost
export MYSQL_PORT=33069
```

3. **Run the sink**:

```bash
python app.py
```

## Message Format

Expected message format from the `enriched_orders` topic:

```json
{
  "id": "uuid-string",
  "order_id": "ORD-123",
  "quantity": 5,
  "unit_price": 49.99,
  "total_value": 249.95,
  "processed_at": 1699267200
}
```

## Integration with ETL Pipeline

### Complete Pipeline Flow

1. **Producer** (Laravel) → Publishes raw order to `raw_orders`
2. **Transform** (Python ETL) → Enriches data, publishes to `enriched_orders`
3. **Sink** (This component) → Consumes from `enriched_orders`, writes to MySQL

### Testing the Full Pipeline

```bash
# 1. Start all services
cd /path/to/project
docker-compose up -d                    # Kafka
cd servers && docker-compose up -d      # Producer, Consumer, MySQL
cd ../etl/transform/example-python && docker-compose up -d  # Transform
cd ../../../etl/sink/example-python && docker-compose up -d  # Sink

# 2. Publish test event via Producer API
curl -X POST http://localhost:7001/api/orders \
  -H "Content-Type: application/json" \
  -d '{
    "order_id": "ORD-001",
    "quantity": 3,
    "unit_price": 25.50
  }'

# 3. Verify data in MySQL
docker exec mysql_servers mysql -uroot -proot app_consumer \
  -e "SELECT * FROM orders ORDER BY created_at DESC LIMIT 5;"
```

## Monitoring

### Check Consumer Status

```bash
# View sink logs
docker-compose logs -f kafka-sink-mysql

# Check consumer group lag
docker exec kafka kafka-consumer-groups.sh \
  --bootstrap-server localhost:9092 \
  --group sink-mysql-workers \
  --describe
```

### Verify Database Writes

```bash
# Connect to MySQL
docker exec -it mysql_servers mysql -uroot -proot app_consumer

# Query orders
SELECT * FROM orders ORDER BY created_at DESC LIMIT 10;

# Check total records
SELECT COUNT(*) as total_orders,
       SUM(total_value) as total_revenue
FROM orders;
```

## Error Handling

The sink implements several error handling strategies:

1. **MySQL Connection Errors**: 5 retry attempts with 5-second delays
2. **Invalid JSON**: Messages are skipped and committed (logged as warnings)
3. **Database Write Errors**: Offsets are NOT committed, allowing retry on next poll
4. **Network Errors**: Consumer will reconnect automatically

## Performance Tuning

For production deployments, consider:

1. **Batch Processing**: Modify to use batch inserts for higher throughput
2. **Connection Pooling**: Implement MySQL connection pooling
3. **Async Processing**: Use asyncio for concurrent message processing
4. **Resource Limits**: Adjust Docker resource limits based on workload

Example modification for batch processing:

```python
# Accumulate messages in batches of 100
batch = []
for msg in consumer:
    batch.append(msg)
    if len(batch) >= 100:
        insert_batch(mysql_conn, batch)
        consumer.commit()
        batch = []
```

## Troubleshooting

### Consumer Not Receiving Messages

```bash
# Check if topic has messages
docker exec kafka kafka-console-consumer.sh \
  --bootstrap-server localhost:9092 \
  --topic enriched_orders \
  --from-beginning \
  --max-messages 5

# Check consumer group offset
docker exec kafka kafka-consumer-groups.sh \
  --bootstrap-server localhost:9092 \
  --group sink-mysql-workers \
  --describe
```

### MySQL Connection Issues

```bash
# Test MySQL connectivity from sink container
docker exec kafka-sink-mysql ping -c 3 mysql_servers

# Check MySQL status
docker exec mysql_servers mysqladmin -uroot -proot status
```

### Reset Consumer Offsets (Development Only)

```bash
# Stop the sink first
docker-compose down

# Reset offsets to earliest
docker exec kafka kafka-consumer-groups.sh \
  --bootstrap-server localhost:9092 \
  --group sink-mysql-workers \
  --topic enriched_orders \
  --reset-offsets \
  --to-earliest \
  --execute

# Restart sink
docker-compose up -d
```

## Educational Context

This sink demonstrates several important data engineering concepts:

- **Consumer Groups**: Enables horizontal scaling with multiple sink instances
- **Offset Management**: Manual commits ensure at-least-once delivery semantics
- **Idempotent Writes**: Upsert pattern prevents duplicate data on retries
- **Connection Resilience**: Retry logic handles transient network failures
- **Graceful Shutdown**: Proper cleanup prevents data loss during restarts

## References

- [Confluent Kafka Python Documentation](https://docs.confluent.io/kafka-clients/python/current/overview.html)
- [MySQL Connector/Python](https://dev.mysql.com/doc/connector-python/en/)
- [Kafka Consumer Concepts](https://kafka.apache.org/documentation/#consumerapi)
