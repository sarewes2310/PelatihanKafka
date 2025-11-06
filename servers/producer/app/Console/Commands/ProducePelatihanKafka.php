<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Junges\Kafka\Facades\Kafka;
use Junges\Kafka\Message\Message;

class ProducePelatihanKafka extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Optional JSON payload lets the trainer craft custom events during demos.
     */
    protected $signature = 'kafka:produce-pelatihan
                            {payload? : JSON encoded payload to send as the message body}';

    /**
     * The console command description.
     */
    protected $description = 'Publish a message to the pelatihan_kafka topic';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $payloadArgument = $this->argument('payload');

        if ($payloadArgument !== null) {
            $body = json_decode($payloadArgument, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error('Invalid JSON payload: ' . json_last_error_msg());

                return self::FAILURE;
            }
        } else {
            // Provide a default payload so trainers can demo the command quickly.
            $body = [
                'event' => 'pelatihan.kafka.demo',
                'message' => 'Belajar Apache Kafka bareng Laravel',
                'producer' => config('app.name'),
                'sent_at' => now()->toIso8601String(),
            ];
        }

        $correlationId = (string) Str::uuid();
        $message = new Message(
            headers: [
                'correlation_id' => $correlationId,
                'app' => config('app.name'),
            ],
            body: $body,
            key: $correlationId
        );

        Kafka::asyncPublish(config('kafka.brokers'))->onTopic('pelatihan_kafka.demo_2')
             ->withHeaders([
                 'correlation_id' => (string) Str::uuid(),
                 'app' => config('app.name'),
             ])
             ->withMessage($message)
             ->send();

        $this->info('Message published to pelatihan_kafka.demo');

        return self::SUCCESS;
    }
}
