<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

class InstallYtDlpCommand extends Command
{
    protected $signature = 'video:install-yt-dlp {--force : Overwrite existing binary if already present}';
    protected $description = 'Download and install the standalone yt-dlp binary into the local bin directory';

    public function handle(): int
    {
        $binPath = config('services.yt_dlp.binary_path', base_path('bin/yt-dlp'));
        $binDir = dirname($binPath);

        if (File::exists($binPath) && ! $this->option('force')) {
            $this->info("yt-dlp binary already exists at: {$binPath}");
            $this->verifyBinary($binPath);
            return Command::SUCCESS;
        }

        $this->info("Ensuring directory exists: {$binDir}");
        if (! File::isDirectory($binDir)) {
            File::makeDirectory($binDir, 0755, true, true);
        }

        $downloadUrl = 'https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp';
        $this->info("Downloading yt-dlp standalone binary from {$downloadUrl}...");

        try {
            $response = Http::withOptions(['follow_redirects' => true])
                ->timeout(120)
                ->get($downloadUrl);

            if (! $response->successful()) {
                $this->error("Failed to download yt-dlp. HTTP status: " . $response->status());
                return Command::FAILURE;
            }

            File::put($binPath, $response->body());
            @chmod($binPath, 0755);

            $this->info("Saved binary to: {$binPath}");
            return $this->verifyBinary($binPath);
        } catch (Exception $e) {
            $this->error("Failed to install yt-dlp: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    protected function verifyBinary(string $binPath): int
    {
        $process = new Process([$binPath, '--version']);
        $process->setTimeout(15);
        $process->run();

        if ($process->isSuccessful()) {
            $version = trim($process->getOutput());
            $this->info("yt-dlp successfully verified! Version: {$version}");
            return Command::SUCCESS;
        }

        $this->warn("yt-dlp binary installed but execution test failed: " . $process->getErrorOutput());
        return Command::FAILURE;
    }
}
