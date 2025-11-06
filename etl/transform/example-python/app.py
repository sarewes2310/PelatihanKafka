import json
import os
import signal
import sys
import time
from typing import Any, Dict

from confluent_kafka import Consumer, Producer


BROKERS = os.getenv("KAFKA_BROKERS", "kafka:9092")
SOURCE_TOPIC = os.getenv("SOURCE_TOPIC", "raw_orders")
TARGET_TOPIC = os.getenv("TARGET_TOPIC", "enriched_orders")
GROUP_ID = os.getenv("ETL_GROUP_ID", "etl-workers")

running = True


def shutdown(signum, frame):
    global running
    running = False
    print("Received shutdown signal. Draining...")


signal.signal(signal.SIGINT, shutdown)
signal.signal(signal.SIGTERM, shutdown)


def build_consumer() -> Consumer:
    return Consumer(
        {
            "bootstrap.servers": BROKERS,
            "group.id": GROUP_ID,
            "auto.offset.reset": "earliest",
        }
    )


def build_producer() -> Producer:
    return Producer({"bootstrap.servers": BROKERS})


def enrich(payload: Dict[str, Any]) -> Dict[str, Any]:
    quantity = payload.get("quantity", 1)
    unit_price = payload.get("unit_price", 0.0)
    payload["total_value"] = round(quantity * unit_price, 2)
    payload["processed_at"] = int(time.time())
    return payload


def main():
    consumer = build_consumer()
    producer = build_producer()
    consumer.subscribe([SOURCE_TOPIC])

    print(
        f"ETL is listening to '{SOURCE_TOPIC}' and writing to '{TARGET_TOPIC}' via {BROKERS}"
    )

    while running:
        msg = consumer.poll(1.0)
        if msg is None:
            continue
        if msg.error():
            print(f"Consumer error: {msg.error()}")
            continue

        try:
            payload = json.loads(msg.value().decode("utf-8"))
        except json.JSONDecodeError:
            print("Skipping message: not valid JSON")
            continue

        transformed = enrich(payload)
        producer.produce(
            TARGET_TOPIC,
            json.dumps(transformed).encode("utf-8"),
            callback=lambda err, _msg: err
            and print(f"Delivery failed: {err}")
            or None,
        )
        producer.poll(0)
        consumer.commit(msg)

    consumer.close()
    producer.flush(10)
    print("ETL stopped cleanly.")


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        sys.exit(0)
