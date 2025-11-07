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
                    ->withOption('session.timeout.ms', '30000')
                    ->withOption('heartbeat.interval.ms', '10000')
                    ->withOption('max.poll.interval.ms', '300000')
                    ->withOption('connections.max.idle.ms', '540000')
                    ->withOption('metadata.max.age.ms', '300000')
                    ->withOption('socket.timeout.ms', '60000')
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

    /**
     * Handle a single Mahasiswa event coming from Kafka.
     */
    private function consumeMahasiswaEvent(ConsumerMessage $message): void
    {
        $this->info('🔍 Processing message...');
        $this->info('  - Topic: ' . $message->getTopicName());
        $this->info('  - Partition: ' . $message->getPartition());
        $this->info('  - Offset: ' . $message->getOffset());
        $this->info('  - Key: ' . ($message->getKey() ?? 'null'));

        $payload = $message->getBody();

        $this->info('  - Payload type: ' . gettype($payload));
        if (is_array($payload)) {
            $this->info('  - Payload keys: ' . implode(', ', array_keys($payload)));
        }

        if (! is_array($payload)) {
            $this->warn('⚠️  Ignoring message: payload is not an array');
            Log::warning('Ignoring Mahasiswa message without JSON body', [
                'topic' => $message->getTopicName(),
                'headers' => $message->getHeaders(),
                'body' => $payload,
            ]);

            return;
        }

        $event = $payload['event'] ?? 'unknown';
        $data = Arr::get($payload, 'data', []);

        $this->info('  - Event: ' . $event);
        $this->info('  - Data type: ' . gettype($data));

        if (! is_array($data)) {
            $this->warn('⚠️  Ignoring message: data is not an array');
            Log::warning('Ignoring Mahasiswa message without data payload', [
                'event' => $event,
                'headers' => $message->getHeaders(),
                'body' => $payload,
            ]);

            return;
        }

        $mahasiswaId = $data['id'] ?? Arr::get($message->getHeaders(), 'id');

        $this->info('  - Mahasiswa ID: ' . ($mahasiswaId ?? 'null'));

        if ($mahasiswaId === null) {
            $this->warn('⚠️  Skipping message: ID is missing');
            Log::warning('Mahasiswa message skipped because the ID is missing', [
                'event' => $event,
                'headers' => $message->getHeaders(),
                'body' => $payload,
            ]);

            return;
        }

        try {
            $this->info('🚀 Executing event handler for: ' . $event);

            match ($event) {
                'mahasiswa.created', 'mahasiswa.updated' => $this->upsertMahasiswa($mahasiswaId, $data),
                'mahasiswa.deleted' => $this->deleteMahasiswa($mahasiswaId),
                default => Log::info('Mahasiswa event ignored', [
                    'event' => $event,
                    'id' => $mahasiswaId,
                ]),
            };

            $this->info('✅ Event processed successfully');
            Log::info('Mahasiswa event triggered', [
                    'event' => $event,
                    'id' => $mahasiswaId,
            ]);
        } catch (\Throwable $exception) {
            $this->error('❌ Failed to process event: ' . $exception->getMessage());
            $this->error('   Stack trace: ' . $exception->getTraceAsString());

            Log::error('Mahasiswa consumer failed while handling message', [
                'event' => $event,
                'id' => $mahasiswaId,
                'exception' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            report($exception);
        }

        $this->info('─────────────────────────────────────────');
    }

    /**
     * Create or update a Mahasiswa record based on the incoming payload.
     */
    private function upsertMahasiswa(string $id, array $payload): void
    {
        $this->info('  📝 Upserting Mahasiswa record...');
        $this->info('     ID: ' . $id);

        $attributes = Arr::only($payload, ['nim', 'name', 'email', 'address']);
        $this->info('     Attributes: ' . json_encode($attributes));

        $mahasiswa = Mahasiswa::withTrashed()->find($id) ?? new Mahasiswa();
        $isNew = !$mahasiswa->exists;

        $this->info('     Record status: ' . ($isNew ? 'NEW' : 'EXISTING'));

        $mahasiswa->id = $mahasiswa->id ?? $id;
        $mahasiswa->fill($attributes);

        if (array_key_exists('deleted_at', $payload)) {
            $deletedAt = $payload['deleted_at'] ? Carbon::parse($payload['deleted_at']) : null;
            $mahasiswa->deleted_at = $deletedAt;
            $this->info('     Deleted at: ' . ($deletedAt ? $deletedAt->toDateTimeString() : 'null'));
        }

        $mahasiswa->save();

        $this->info('     ✓ Saved to database');
        $this->info(sprintf(
            '  ✅ Mahasiswa %s %s.',
            $mahasiswa->id,
            $isNew ? 'created' : 'updated'
        ));
    }

    /**
     * Soft delete a Mahasiswa entry when a delete event is received.
     */
    private function deleteMahasiswa(string $id): void
    {
        $this->info('  🗑️  Deleting Mahasiswa record...');
        $this->info('     ID: ' . $id);

        $mahasiswa = Mahasiswa::withTrashed()->find($id);

        if ($mahasiswa === null) {
            $this->warn('     ⚠️  Record not found, skipping delete');
            $this->info(sprintf(
                '  ℹ️  Mahasiswa %s delete event ignored (record does not exist).',
                $id
            ));

            return;
        }

        $wasAlreadyDeleted = $mahasiswa->trashed();
        $mahasiswa->delete();

        $this->info('     ✓ Soft deleted');
        $this->info(sprintf(
            '  ✅ Mahasiswa %s marked as deleted%s.',
            $id,
            $wasAlreadyDeleted ? ' (was already deleted)' : ''
        ));
    }
}
