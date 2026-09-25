<?php

namespace App\Services;

use RuntimeException;

/**
 * Streaming file encryption for database dumps (libsodium secretstream,
 * XChaCha20-Poly1305). The dump never has to fit in memory: it is processed in
 * 64 KB chunks, each one authenticated, and the last chunk carries the FINAL tag,
 * so a truncated file fails to decrypt.
 *
 * File layout: MAGIC | secretstream header | encrypted chunks.
 */
class BackupCipher
{
    private const MAGIC = "LTABAK1\n";

    private const CHUNK = 65536;

    /** Decodes a key from config (base64, optionally prefixed with "base64:"). */
    public static function decodeKey(string $key): string
    {
        self::ensureSodium();

        $raw = base64_decode(preg_replace('/^base64:/', '', trim($key)) ?? '', true);

        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY должен быть 32-байтным ключом в base64 (php artisan app:backup-key).');
        }

        return $raw;
    }

    /** Generates a new key in the format accepted by decodeKey(). */
    public static function generateKey(): string
    {
        self::ensureSodium();

        return 'base64:'.base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    public function encrypt(string $source, string $target, string $key): void
    {
        [$in, $out] = $this->open($source, $target);

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            $this->write($out, self::MAGIC.$header);

            $remaining = (int) filesize($source);
            do {
                $chunk = $remaining > 0 ? (string) fread($in, min(self::CHUNK, $remaining)) : '';
                if ($remaining > 0 && $chunk === '') {
                    throw new RuntimeException('Не удалось прочитать дамп для шифрования.');
                }
                $remaining -= strlen($chunk);
                $tag = $remaining <= 0
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                $this->write($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag));
            } while ($remaining > 0);
        } catch (\Throwable $e) {
            fclose($out);
            $out = null;
            @unlink($target);

            throw $e;
        } finally {
            fclose($in);
            if ($out !== null) {
                fclose($out);
            }
        }
    }

    public function decrypt(string $source, string $target, string $key): void
    {
        [$in, $out] = $this->open($source, $target);

        try {
            $magic = (string) fread($in, strlen(self::MAGIC));
            $header = (string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if ($magic !== self::MAGIC || strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new RuntimeException('Файл не является зашифрованной резервной копией.');
            }

            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $final = false;

            while (! $final) {
                $chunk = (string) fread($in, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
                $result = $chunk === '' ? false : sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);
                if ($result === false) {
                    throw new RuntimeException('Не удалось расшифровать: неверный ключ или файл повреждён.');
                }

                [$plain, $tag] = $result;
                $this->write($out, $plain);
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }

            if (fread($in, 1) !== '') {
                throw new RuntimeException('Не удалось расшифровать: лишние данные после конца потока.');
            }
        } catch (\Throwable $e) {
            fclose($out);
            $out = null;
            @unlink($target);

            throw $e;
        } finally {
            fclose($in);
            if ($out !== null) {
                fclose($out);
            }
        }
    }

    /** @return array{0: resource, 1: resource} */
    private function open(string $source, string $target): array
    {
        self::ensureSodium();

        $in = @fopen($source, 'rb');
        if ($in === false) {
            throw new RuntimeException('Не удалось открыть файл: '.$source);
        }

        // "x" refuses to overwrite: an existing file is never clobbered.
        $out = @fopen($target, 'xb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('Не удалось создать файл (возможно, он уже существует): '.$target);
        }

        return [$in, $out];
    }

    /** @param resource $handle */
    private function write($handle, string $data): void
    {
        if ($data !== '' && fwrite($handle, $data) !== strlen($data)) {
            throw new RuntimeException('Не удалось записать файл резервной копии.');
        }
    }

    private static function ensureSodium(): void
    {
        if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            throw new RuntimeException('Для шифрования резервных копий нужно PHP-расширение sodium.');
        }
    }
}
