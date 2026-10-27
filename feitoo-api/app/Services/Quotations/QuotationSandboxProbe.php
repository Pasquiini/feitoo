<?php

declare(strict_types=1);

namespace App\Services\Quotations;

use RuntimeException;

class QuotationSandboxProbe
{
    private mixed $listener = null;

    public function prepare(string $workspace, string $canary): array
    {
        if (! symlink($canary, $workspace.'/escape')) {
            throw new RuntimeException;
        }
        $this->listener = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($this->listener === false) {
            throw new RuntimeException;
        }
        $address = 'tcp://'.stream_socket_get_name($this->listener, false);
        $control = @stream_socket_client($address, $errno, $error, 1);
        if ($control === false) {
            throw new RuntimeException;
        }
        fclose($control);
        $accepted = @stream_socket_accept($this->listener, 1);
        if ($accepted === false) {
            throw new RuntimeException;
        }
        fclose($accepted);
        $namespace = @readlink('/proc/self/ns/net');
        if (! is_string($namespace) || $namespace === '') {
            throw new RuntimeException;
        }

        return ['network_address' => $address, 'network_namespace' => $namespace];
    }

    public function close(): void
    {
        if (is_resource($this->listener)) {
            fclose($this->listener);
        }
        $this->listener = null;
    }
}
