<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Enums\ProcessingFileError;
use App\Support\Documents\DocumentException;

final class LibreOfficeDocxToPdfConverter implements DocxToPdfConverter
{
    public function __construct(private ConversionProcessRunner $runner, private PdfOutputValidator $pdf) {}

    public function convert(string $input, string $workspace): string
    {
        if (config('documents.driver') !== 'libreoffice') {
            throw new DocumentException(ProcessingFileError::ConverterUnavailable);
        }
        $profile = $workspace.'/profile';
        $output = $workspace.'/output';
        foreach ([$profile.'/user', $output] as $directory) {
            if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
                throw new DocumentException(ProcessingFileError::ConverterUnavailable);
            }
        }
        // Refuse macros, link updates and recovery prompts even in a fresh profile.
        file_put_contents($profile.'/user/registrymodifications.xcu', '<?xml version="1.0"?><oor:items xmlns:oor="http://openoffice.org/2001/registry"><item oor:path="/org.openoffice.Office.Common/Security/Scripting"><prop oor:name="MacroSecurityLevel" oor:op="fuse"><value>3</value></prop><prop oor:name="DisableMacrosExecution" oor:op="fuse"><value>true</value></prop><prop oor:name="DisableActiveContent" oor:op="fuse"><value>true</value></prop><prop oor:name="DisableOLEAutomation" oor:op="fuse"><value>true</value></prop></item><item oor:path="/org.openoffice.Office.Writer/Content/Update"><prop oor:name="Link" oor:op="fuse"><value>2</value></prop></item></oor:items>');
        $sandbox = config('documents.sandbox');
        $inside = $sandbox === 'bubblewrap' ? '/work' : str_replace('\\', '/', $workspace);
        $profileUri = 'file://'.(str_starts_with($inside, '/') ? '' : '/').implode('/', array_map('rawurlencode', explode('/', $inside.'/profile')));
        $args = [config('documents.binary'), '-env:UserInstallation='.$profileUri,
            '--headless', '--nologo', '--nodefault', '--nofirststartwizard', '--norestore',
            '--convert-to', 'pdf:writer_pdf_Export', '--outdir', $inside.'/output', $inside.'/input.docx'];
        if ($sandbox === 'bubblewrap' && PHP_OS_FAMILY === 'Linux') {
            // Isolated namespaces, no network, no application files/secrets. Only /work is writable on the host.
            $prefix = [config('documents.prlimit_binary'), '--as='.config('documents.memory_bytes'),
                '--cpu='.(config('documents.timeout') + 2), '--fsize='.config('documents.max_pdf_bytes'), '--',
                config('documents.bubblewrap_binary'), '--unshare-all', '--die-with-parent', '--new-session',
                '--cap-drop', 'ALL', '--ro-bind', '/usr', '/usr', '--tmpfs', '/usr/local', '--symlink', 'usr/bin', '/bin'];
            foreach (['/lib', '/lib64', '/etc/fonts', '/etc/ld.so.cache'] as $mount) {
                if (file_exists($mount)) {
                    array_push($prefix, '--ro-bind', $mount, $mount);
                }
            }
            array_push($prefix, '--proc', '/proc', '--dev', '/dev', '--tmpfs', '/tmp', '--bind', $workspace, '/work',
                '--chdir', '/work', '--clearenv', '--setenv', 'HOME', '/work', '--setenv', 'PATH', '/usr/bin:/bin',
                '--setenv', 'SAL_USE_VCLPLUGIN', 'svp', '--setenv', 'LANG', 'C.UTF-8', '--');
            $args = [...$prefix, ...$args];
        } elseif ($sandbox !== 'none' || app()->environment('production')) {
            throw new DocumentException(ProcessingFileError::ConverterUnavailable);
        }
        $code = $this->runner->run($args, $workspace, config('documents.timeout'));
        if (in_array($code, [126, 127, 1], true) && ! is_file($output.'/input.pdf')) {
            throw new DocumentException(ProcessingFileError::ConverterUnavailable);
        }
        if ($code !== 0) {
            throw new DocumentException(ProcessingFileError::ConversionFailed);
        }
        $this->pdf->validate($output.'/input.pdf');

        return $output.'/input.pdf';
    }
}
