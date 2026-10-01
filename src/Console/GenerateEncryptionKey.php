<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Twstec\Kit\Uploads\Confidential\Keyring;

/**
 * Gera a chave de cifra dos uploads CONFIDENCIAIS (UPLOADS_ENCRYPTION_KEY) —
 * própria, separada da APP_KEY — e a grava no `.env`, como o `key:generate`
 * faz com a APP_KEY.
 *
 * - Sem chave ainda: gera e grava.
 * - Já com chave: RECUSA (trocar a chave sem levar a antiga deixaria os
 *   arquivos cifrados sem leitura). Para trocar, `--rotate`: a chave nova
 *   vira a atual e a antiga vai para o começo de
 *   UPLOADS_ENCRYPTION_PREVIOUS_KEYS — os arquivos antigos continuam
 *   legíveis — e o próximo passo é `php artisan uploads:reencrypt`.
 * - `--show`: só mostra uma chave nova; não grava nada (para cofre de
 *   segredos, variável de ambiente do orquestrador).
 *
 * Em produção pede confirmação (ou `--force`). A chave nunca vai para o log;
 * só aparece na tela com `--show`.
 */
final class GenerateEncryptionKey extends Command
{
    use ConfirmableTrait;

    protected $signature = 'uploads:encryption-key
        {--show : Só mostra uma chave nova; não grava no .env}
        {--rotate : Troca a chave atual por uma nova, mantendo a atual como anterior (para decifrar até o uploads:reencrypt terminar)}
        {--force : Não pede confirmação em produção}';

    protected $description = 'Gera (ou troca, com --rotate) a chave de cifra dos uploads confidenciais.';

    public function handle(): int
    {
        if (! Keyring::sodiumAvailable()) {
            $this->components->error('A extensão sodium do PHP (ext-sodium) não está instalada neste servidor: uploads confidenciais não funcionam sem ela. Instale-a (no PHP oficial e na imagem do kit ela já vem) e rode o comando de novo.');

            return self::FAILURE;
        }

        $nova = Keyring::generate();

        if ($this->option('show')) {
            $this->line('<comment>'.$nova.'</comment>');

            return self::SUCCESS;
        }

        $atual = trim((string) config('uploads.confidential.key', ''));
        $rotacao = (bool) $this->option('rotate');

        if ($atual !== '' && ! $rotacao) {
            $this->components->error('UPLOADS_ENCRYPTION_KEY já está configurada. Trocar a chave sem guardar a antiga deixaria os arquivos cifrados sem leitura: use --rotate.');

            return self::FAILURE;
        }

        if ($rotacao && Keyring::decode($atual) === null) {
            $this->components->error('--rotate precisa de uma UPLOADS_ENCRYPTION_KEY atual válida (ela vira a anterior). Sem chave atual, rode sem --rotate.');

            return self::FAILURE;
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $valores = ['UPLOADS_ENCRYPTION_KEY' => $nova];

        if ($rotacao) {
            $anteriores = array_values(array_filter(array_map('trim', (array) config('uploads.confidential.previous_keys', [])), fn (string $chave): bool => $chave !== ''));
            $valores['UPLOADS_ENCRYPTION_PREVIOUS_KEYS'] = implode(',', array_values(array_unique([$atual, ...$anteriores])));
        }

        if (! $this->writeEnvironment($valores)) {
            return self::FAILURE;
        }

        config([
            'uploads.confidential.key' => $nova,
            'uploads.confidential.previous_keys' => isset($valores['UPLOADS_ENCRYPTION_PREVIOUS_KEYS']) ? explode(',', $valores['UPLOADS_ENCRYPTION_PREVIOUS_KEYS']) : config('uploads.confidential.previous_keys', []),
        ]);

        if ($rotacao) {
            $this->components->info('Chave dos uploads confidenciais trocada. A anterior continua decifrando os arquivos antigos.');
            $this->components->bulletList([
                'Se a configuração estiver em cache: php artisan config:cache (ou config:clear).',
                'Depois: php artisan uploads:reencrypt — recifra os arquivos com a chave nova, sem tirá-los do ar.',
                'Só tire a chave antiga de UPLOADS_ENCRYPTION_PREVIOUS_KEYS quando o uploads:reencrypt disser que nenhum arquivo a usa.',
            ]);
        } else {
            $this->components->info('Chave dos uploads confidenciais gravada no .env (UPLOADS_ENCRYPTION_KEY). Guarde uma cópia no cofre de segredos: sem ela, os arquivos confidenciais não decifram.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $valores
     */
    private function writeEnvironment(array $valores): bool
    {
        $arquivo = $this->laravel->environmentFilePath();

        if (! is_file($arquivo) || ! is_writable($arquivo)) {
            $this->components->error("Não foi possível gravar em {$arquivo}. Use --show e configure a variável no ambiente.");

            return false;
        }

        $conteudo = (string) file_get_contents($arquivo);

        foreach ($valores as $nome => $valor) {
            $linha = $nome.'='.$valor;
            $padrao = '/^'.preg_quote($nome, '/').'=.*$/m';

            $conteudo = preg_match($padrao, $conteudo) === 1
                ? (string) preg_replace_callback($padrao, static fn (): string => $linha, $conteudo, 1)
                : rtrim($conteudo, "\n")."\n".$linha."\n";
        }

        file_put_contents($arquivo, $conteudo);

        return true;
    }
}
