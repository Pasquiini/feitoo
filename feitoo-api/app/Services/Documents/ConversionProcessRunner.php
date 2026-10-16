<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Enums\ProcessingFileError;
use App\Support\Documents\DocumentException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class ConversionProcessRunner
{
    public function run(array $arguments, string $workspace, int $timeout): int
    {
        $process = new Process($arguments, $workspace, [
            'HOME' => $workspace, 'TMPDIR' => $workspace, 'SAL_USE_VCLPLUGIN' => 'svp',
        ], null, $timeout);
        // Do not retain stdout/stderr: they can contain document text or paths.
        $process->disableOutput();
        try {
            return $process->run();
        } catch (ProcessTimedOutException) {
            throw new DocumentException(ProcessingFileError::ConversionTimeout);
        } catch (Throwable) {
            throw new DocumentException(ProcessingFileError::ConverterUnavailable);
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }
}
