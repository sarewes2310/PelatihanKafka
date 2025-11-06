# Quick Start Guide - Kafka MySQL Sink

This guide will help you get the Kafka MySQL Sink up and running in 5 minutes.

## Prerequisites

Ensure the following services are running:

- ✅ Kafka broker (`pelatihankafka_default` network)
- ✅ MySQL server (`mysql_servers` container)
- ✅ Transform ETL (optional, for enriched data)

## Step 1: Start Required Services

```bash
# Navigate to project root
cd /path/to/PemateriKafka

# Start Kafka broker
docker-compose up -d

# Start MySQL and Laravel services
cd servers
docker-compose up -d

# Verify MySQL is ready
docker exec mysql_servers mysqladmin -uroot -proot ping
```

## Step 2: Start the Sink

```bash
# Navigate to sink directory
cd etl/sink/example-python

# Build and start the sink
docker-compose up -d

# View logs
docker-compose logs -f kafka-sink-mysql
```

Expected output:

```
kafka-sink-mysql | Connected to MySQL at mysql_servers:3306/app_consumer
kafka-sink-mysql | Table `orders` ready
kafka-sink-mysql | Sink is consuming from 'enriched_orders' and writing to MySQL mysql_servers/app_consumer.orders
```

## Step 3: Send Test Data

### Option A: Using Transform ETL

If you have the transform ETL running:

```bash
# Start transform service
cd etl/transform/example-python
docker-compose up -d

# Send raw order via producer
docker exec kafka kafka-console-producer.sh \
  --bootstrap-server localhost:9092 \
  --topic raw_orders
```

Then type:

```json
{"order_id":"TEST-001","quantity":5,"unit_price":19.99}
```

### Option B: Direct to enriched_orders

Send data directly to the sink's source topic:

```bash
docker exec kafka kafka-console-producer.sh \
  --bootstrap-server localhost:9092 \
  --topic enriched_orders
```

Then type:

```json
{"id":"550e8400-e29b-41d4-a716-446655440000","order_id":"TEST-002","quantity":3,"unit_price":25.50,"total_value":76.50,"processed_at":1699267200}
```

Press `Ctrl+D` to exit the producer.

## Step 4: Verify Data in MySQL

```bash
# Connect to MySQL
docker exec -it mysql_servers mysql -uroot -proot app_consumer

# Query the orders table
SELECT * FROM orders ORDER BY created_at DESC LIMIT 5;
```

Expected result:

```
+--------------------------------------+-----------+----------+------------+-------------+--------------+---------------------+---------------------+
| id                                   | order_id  | quantity | unit_price | total_value | processed_at | created_at          | updated_at          |
+--------------------------------------+-----------+----------+------------+-------------+--------------+---------------------+---------------------+
| 550e8400-e29b-41d4-a716-446655440000 | TEST-002  |        3 |      25.50 |       76.50 |   1699267200 | 2024-11-06 12:00:00 | 2024-11-06 12:00:00 |
+--------------------------------------+-----------+----------+------------+-------------+--------------+---------------------+---------------------+
```

## Step 5: Monitor Consumer

Check consumer group status:

```bash
docker exec kafka kafka-consumer-groups.sh \
  --bootstrap-server localhost:9092 \
  --group sink-mysql-workers \
  --describe
```

## Common Commands

### View Sink Logs

```bash
docker-compose logs -f kafka-sink-mysql
```

### Restart Sink

```bash
docker-compose restart kafka-sink-mysql
```

### Stop Sink

```bash
docker-compose down
```

### Rebuild After Code Changes

```bash
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

## Troubleshooting

### Sink Not Receiving Messages

1. **Check if topic exists:**

```bash
docker exec kafka kafka-topics.sh \
  --bootstrap-server localhost:9092 \
  --list | grep enriched_orders
```

2. **Check if topic has messages:**

```bash
docker exec kafka kafka-console-consumer.sh \
  --bootstrap-server localhost:9092 \
  --topic enriched_orders \
  --from-beginning \
  --max-messages 1
```

3. **Check consumer group lag:**

```bash
docker exec kafka kafka-consumer-groups.sh \
  --bootstrap-server localhost:9092 \
  --group sink-mysql-workers \
  --describe
```

### MySQL Connection Issues

1. **Check MySQL is running:**

```bash
docker ps | grep mysql_servers
```

2. **Test connectivity from sink container:**

```bash
docker exec kafka-sink-mysql ping -c 3 mysql_servers
```

3. **Verify MySQL credentials:**

```bash
docker exec mysql_servers mysql -uroot -proot -e "SELECT 1"
```

### Reset and Start Fresh

```bash
# Stop sink
docker-compose down

# Reset consumer group offsets
docker exec kafka kafka-consumer-groups.sh \
  --bootstrap-server localhost:9092 \
  --group sink-mysql-workers \
  --topic enriched_orders \
  --reset-offsets \
  --to-earliest \
  --execute

# Clear MySQL table (optional)
docker exec mysql_servers mysql -uroot -proot app_consumer \
  -e "TRUNCATE TABLE orders;"

# Restart sink
docker-compose up -d
```

## Next Steps

- Read the [full README](./README.md) for detailed documentation
- Learn about [performance tuning](./README.md#performance-tuning)
- Explore [error handling strategies](./README.md#error-handling)
- Review the [complete ETL pipeline guide](../../../KAFKA_ETL_GUIDE.md)

## Need Help?

Check the logs for detailed error messages:

```bash
docker-compose logs kafka-sink-mysql
```

Verify network connectivity:

```bash
docker network inspect pelatihankafka_default
```
