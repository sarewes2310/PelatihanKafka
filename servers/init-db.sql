-- Create the app_consumer database
CREATE DATABASE IF NOT EXISTS app_consumer;

-- Create the app_producer database
CREATE DATABASE IF NOT EXISTS app_producer;

-- Grant privileges to root user on both databases
GRANT ALL PRIVILEGES ON app_consumer.* TO 'root'@'%';
GRANT ALL PRIVILEGES ON app_producer.* TO 'root'@'%';

FLUSH PRIVILEGES;
