<?php

namespace App\Console\Commands;

use App\Models\Mahasiswa;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\ConsumerMessage;
use Junges\Kafka\Facades\Kafka;

class ConsumeMahasiswaKafka extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'kafka:consume-mahasiswa';

    /**
     * The console command description.
     */
    protected $description = 'Consume Mahasiswa events from pelatihan_kafka.producer.mahasiswa topic';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $topic = 'pelatihan_kafka.producer.mahasiswa';
        $groupId = config('kafka.consumer_group_id');

        $this->info("Consuming from topic: {$topic} (group: {$groupId})");

        $retries = 3;
        $delay = 5;

        for ($i = 1; $i <= $retries; $i++) {
            try {
                if ($i > 1) {
                    $this->warn("Retry {$i}/{$retries}, waiting {$delay}s...");
                    sleep($delay);
                }

                Kafka::consumer([$topic], $groupId)
                    ->withBrokers(config('kafka.brokers'))
                    ->withAutoCommit()
                    ->withOption('auto.offset.reset', 'earliest')
                    ->withOption('session.timeout.ms', 30000) // 30 seconds - increased from default
                    ->withOption('heartbeat.interval.ms', 10000) // 10 seconds heartbeat
                    ->withOption('max.poll.interval.ms', 300000) // 5 minutes max poll interval
                    ->withOption('connections.max.idle.ms', 540000) // 9 minutes connection idle
                    ->withOption('metadata.max.age.ms', 300000) // 5 minutes metadata refresh
                    ->withOption('socket.timeout.ms', 60000) // 60 seconds socket timeout
                    ->withHandler(function (ConsumerMessage $message) {
                        $this->consumeMahasiswaEvent($message);
                    })
                    ->build()
                    ->consume();

                return self::SUCCESS;
            } catch (\Throwable $e) {
                $msg = $e->getMessage();

                if (str_contains($msg, 'COORDINATOR_NOT_AVAILABLE') ||
                    str_contains($msg, 'NOT_COORDINATOR') ||
                    str_contains($msg, 'GROUP_COORDINATOR_NOT_AVAILABLE')) {

                    if ($i < $retries) {
                        $this->error("Coordinator unavailable, retrying...");
                        continue;
                    }
                }

                $this->error("Consumer failed: {$msg}");
                throw $e;
            }
        }

        $this->error("Unable to start consumer after {$retries} attempts");
        return self::FAILURE;
    }

    private function consumeMahasiswaEvent(ConsumerMessage $message): void
    {
        $payload = $message->getBody();

        if (!is_array($payload)) {
            Log::warning('Invalid message payload', [
                'topic' => $message->getTopicName(),
                'body' => $payload,
            ]);
            return;
        }

        $event = $payload['event'] ?? 'unknown';
        $data = Arr::get($payload, 'data', []);

        if (!is_array($data)) {
            Log::warning('Missing data in message', ['event' => $event]);
            return;
        }

        $id = $data['id'] ?? Arr::get($message->getHeaders(), 'id');

        if (!$id) {
            Log::warning('Message missing ID', ['event' => $event]);
            return;
        }

        try {
            match ($event) {
                'mahasiswa.created', 'mahasiswa.updated' => $this->upsertMahasiswa($id, $data),
                'mahasiswa.deleted' => $this->deleteMahasiswa($id),
                default => Log::info('Unknown event type', ['event' => $event, 'id' => $id]),
            };

            Log::info('Processed event', ['event' => $event, 'id' => $id]);
        } catch (\Throwable $e) {
            Log::error('Failed to process message', [
                'event' => $event,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            report($e);
        }
    }

    private function upsertMahasiswa(string $id, array $payload): void
    {
        $attributes = Arr::only($payload, ['nim', 'name', 'email', 'address']);

        $mahasiswa = Mahasiswa::withTrashed()->find($id) ?? new Mahasiswa();
        $mahasiswa->id = $mahasiswa->id ?? $id;
        $mahasiswa->fill($attributes);

        if (array_key_exists('deleted_at', $payload)) {
            $mahasiswa->deleted_at = $payload['deleted_at']
                ? Carbon::parse($payload['deleted_at'])
                : null;
        }

        $mahasiswa->save();
    }

    private function deleteMahasiswa(string $id): void
    {
        $mahasiswa = Mahasiswa::withTrashed()->find($id);

        if (!$mahasiswa) {
            return;
        }

        $mahasiswa->delete();
    }
}
