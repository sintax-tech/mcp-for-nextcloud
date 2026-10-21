<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files\Presentation;

use OCA\Mcp\Mcp\Confirmation\ConfirmationMessages;
use OCA\Mcp\Platform\FileAccess\FileAccessMessages;
use OCA\Mcp\Platform\FileAccess\Sharing\SharingMessages;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Files\Checkout\CheckoutMessages;
use OCA\Mcp\Modules\Files\Editing\EditingMessages;
use OCA\Mcp\Modules\Files\FilesModule;
use OCA\Mcp\Modules\Files\Reading\ReadingMessages;
use OCA\Mcp\Modules\Files\Reorganization\ReorganizationMessages;
use OCA\Mcp\Modules\Files\Versions\VersionMessages;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use PHPUnit\Framework\TestCase;

/**
 * What the user of the Files module sees comes out in the language of the account; what the model reads
 * (the tool descriptions) stays English in every language.
 */
final class FilesTranslationTest extends TestCase {
    protected function tearDown(): void {
        Translator::reset();
    }

    public function testPortugueseUserGetsTheSameTextsAsBefore(): void {
        Translator::use(new JsonL10n('pt_BR'));

        self::assertSame('Recurso não encontrado no Nextcloud.', FileAccessMessages::notFound());
        self::assertSame('Sem acesso a este recurso no Nextcloud.', FileAccessMessages::forbidden());
        self::assertSame('Recurso bloqueado por outra operação; tente novamente.', FileAccessMessages::locked());
        self::assertSame('O recurso foi alterado desde a última leitura (etag divergente); nada foi gravado.', FileAccessMessages::conflict());

        self::assertSame('não identificado', SharingMessages::unidentified());
        self::assertSame('Alterações afetam outras pessoas. Confirme com o usuário antes de continuar e repita a chamada com confirm_shared: true.', ConfirmationMessages::confirmAdvice());
        self::assertSame('Alterações afetam outras pessoas. Confirme com o usuário antes de continuar e faça um novo files_checkout com confirm_shared: true, porque este link de edição já foi gasto.', ConfirmationMessages::confirmAdviceCheckout());
        self::assertSame('Este arquivo está na pasta de time "Engenharia" (Team Folder).', SharingMessages::teamFolder('Engenharia'));
        self::assertSame('Este arquivo foi compartilhado por Paulo Exemplo.', SharingMessages::sharedBy('Paulo Exemplo'));
        self::assertSame('Este arquivo está em um armazenamento externo.', SharingMessages::externalStorage());
        self::assertSame('Espaço insuficiente na cota do usuário.', FileAccessMessages::insufficientQuota());
        self::assertSame('O caminho informado não é um arquivo.', FileAccessMessages::notAFile());
        self::assertSame('Já existe um arquivo com o nome da pasta de destino.', FileAccessMessages::fileInTargetFolderPath());

        self::assertSame('Lote não encontrado.', ReorganizationMessages::batchNotFound());
        self::assertSame('Este lote já foi desfeito.', ReorganizationMessages::batchAlreadyUndone());
        self::assertSame('O item não é mais o que este lote moveu; nada foi desfeito.', ReorganizationMessages::notTheBatchNode());
        self::assertSame('O caminho informado não é uma pasta.', ReadingMessages::notAFolder());
        self::assertSame('Já existe um arquivo ou pasta neste destino.', ReorganizationMessages::destinationExists());
        self::assertSame('O trecho informado não aparece no arquivo.', EditingMessages::snippetMissing());
        self::assertSame('O trecho informado não é UTF-8 válido; envie-o exatamente como aparece no arquivo.', EditingMessages::snippetNotUtf8());
        self::assertSame('Conteúdo excede o limite de edição de 100 bytes.', EditingMessages::editTooLarge(100));
        self::assertSame('O versionamento de arquivos (files_versions) não está ativo nesta conta.', VersionMessages::versionsOff());
        self::assertSame('Envie os bytes do arquivo como corpo bruto (curl -T), não como formulário multipart.', CheckoutMessages::uploadMultipart());
        self::assertSame('O corpo enviado está vazio; nada foi gravado.', CheckoutMessages::uploadEmpty());
        self::assertSame('Recurso excede o limite de leitura binária de 524288 bytes.', ReadingMessages::binaryResourceTooLarge(524288));
    }

    public function testSpanishUserGetsSpanishTexts(): void {
        $english = [
            FileAccessMessages::notFound(),
            FileAccessMessages::forbidden(),
            SharingMessages::externalStorage(),
            ConfirmationMessages::confirmAdvice(),
            ReorganizationMessages::destinationExists(),
            CheckoutMessages::uploadMultipart(),
            VersionMessages::versionsOff(),
            EditingMessages::editTooLarge(100),
        ];

        Translator::use(new JsonL10n('es'));

        $translated = [
            FileAccessMessages::notFound(),
            FileAccessMessages::forbidden(),
            SharingMessages::externalStorage(),
            ConfirmationMessages::confirmAdvice(),
            ReorganizationMessages::destinationExists(),
            CheckoutMessages::uploadMultipart(),
            VersionMessages::versionsOff(),
            EditingMessages::editTooLarge(100),
        ];

        foreach ($translated as $index => $text) {
            self::assertNotSame($english[$index], $text);
            self::assertNotSame('', $text);
        }

        self::assertSame('Recurso no encontrado en Nextcloud.', FileAccessMessages::notFound());
        self::assertSame('Sin acceso a este recurso en Nextcloud.', FileAccessMessages::forbidden());
        self::assertSame('Este archivo está en un almacenamiento externo.', SharingMessages::externalStorage());
        self::assertSame('Ya existe un archivo o carpeta en este destino.', ReorganizationMessages::destinationExists());
        self::assertSame('Envíe los bytes del archivo como cuerpo sin procesar (curl -T), no como formulario multiparte.', CheckoutMessages::uploadMultipart());
        self::assertSame('El versionado de archivos (files_versions) no está activo en esta cuenta.', VersionMessages::versionsOff());
        self::assertSame('El recurso excede el límite de lectura binaria de 524288 bytes.', ReadingMessages::binaryResourceTooLarge(524288));
    }

    public function testWithoutTranslatorOrWithUnknownLanguageTheMessagesAreEnglish(): void {
        Translator::reset();
        self::assertSame('Resource not found in Nextcloud.', FileAccessMessages::notFound());
        self::assertSame('A file or folder already exists at this destination.', ReorganizationMessages::destinationExists());
        self::assertSame('This file is on an external storage.', SharingMessages::externalStorage());

        Translator::use(new JsonL10n('fr'));
        self::assertSame('Resource not found in Nextcloud.', FileAccessMessages::notFound());
        self::assertSame('A file or folder already exists at this destination.', ReorganizationMessages::destinationExists());
        self::assertSame('This file is on an external storage.', SharingMessages::externalStorage());
    }

    /** The descriptions are read by the model, so they never change with the language of the account. */
    public function testDescriptionsAreFixedEnglish(): void {
        $ref = new \ReflectionClass(FilesModule::class);
        $module = $ref->newInstanceWithoutConstructor();

        Translator::use(new JsonL10n('pt_BR'));
        $definitions = $module->definitions();
        $texts = [];
        array_walk_recursive($definitions, static function ($value, $key) use (&$texts): void {
            if ($key === 'description' && is_string($value)) {
                $texts[] = $value;
            }
        });

        self::assertGreaterThanOrEqual(15, count($texts));
        foreach ($texts as $text) {
            self::assertDoesNotMatchRegularExpression('/[À-ÿ]/u', $text);
        }
        self::assertSame(ReadingMessages::listTool(), $definitions[0]['description']);
    }
}
