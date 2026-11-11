<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Support;

use Composer\Autoload\ClassLoader;

/**
 * Original extraction identities retained for installed callers and serialized queue payloads.
 *
 * All implementations live in the normal package directories. Do not add new Managed names.
 *
 * @return array<string, string> Original identity => canonical implementation.
 *
 * @internal
 */
function legacyManagedNamespaces(): array
{
    return [
        'Nvl\\MailNotifications\\Managed\\Console\\Commands\\DispatchPendingMail' => 'Nvl\\MailNotifications\\Console\\Commands\\DispatchPendingMail',
        'Nvl\\MailNotifications\\Managed\\Console\\Commands\\PruneMailHistory' => 'Nvl\\MailNotifications\\Console\\Commands\\PruneMailHistory',
        'Nvl\\MailNotifications\\Managed\\Console\\Commands\\PruneMessageAttachments' => 'Nvl\\MailNotifications\\Console\\Commands\\PruneMessageAttachments',
        'Nvl\\MailNotifications\\Managed\\Console\\Commands\\PruneOrphanMailFiles' => 'Nvl\\MailNotifications\\Console\\Commands\\PruneOrphanMailFiles',
        'Nvl\\MailNotifications\\Managed\\Console\\Commands\\QuarantineRestoredMail' => 'Nvl\\MailNotifications\\Console\\Commands\\QuarantineRestoredMail',
        'Nvl\\MailNotifications\\Managed\\Console\\Commands\\ReconcileMailWebhooks' => 'Nvl\\MailNotifications\\Console\\Commands\\ReconcileMailWebhooks',
        'Nvl\\MailNotifications\\Managed\\Console\\Commands\\RecoverClientWebhooks' => 'Nvl\\MailNotifications\\Console\\Commands\\RecoverClientWebhooks',
        'Nvl\\MailNotifications\\Managed\\Console\\Commands\\RecoverProviderSyncs' => 'Nvl\\MailNotifications\\Console\\Commands\\RecoverProviderSyncs',
        'Nvl\\MailNotifications\\Managed\\Console\\Commands\\SyncMailIntegrations' => 'Nvl\\MailNotifications\\Console\\Commands\\SyncMailIntegrations',
        'Nvl\\MailNotifications\\Managed\\Contracts\\EmailProvider' => 'Nvl\\MailNotifications\\Contracts\\EmailProvider',
        'Nvl\\MailNotifications\\Managed\\Contracts\\ProviderApiAdapter' => 'Nvl\\MailNotifications\\Contracts\\ProviderApiAdapter',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\AssetFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\AssetFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\ClientApiKeyFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\ClientApiKeyFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\ClientFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\ClientFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\ClientMailSettingsFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\ClientMailSettingsFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\ClientWebhookDeliveryFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\ClientWebhookDeliveryFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\ClientWebhookEndpointFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\ClientWebhookEndpointFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\EmailAttemptFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\EmailAttemptFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\EmailEventFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\EmailEventFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\EmailFlagRuleFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\EmailFlagRuleFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\EmailMessageFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\EmailMessageFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\EmailMessageFlagFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\EmailMessageFlagFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\EmailMessageReferenceFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\EmailMessageReferenceFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\EmailRecipientFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\EmailRecipientFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\IntegrationFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\IntegrationFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\MarketingOptOutFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\MarketingOptOutFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\MessageAssetFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\MessageAssetFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\OwnerFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\OwnerFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\OwnerMailSettingsFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\OwnerMailSettingsFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\ProviderAccountFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\ProviderAccountFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\ProviderSyncRunFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\ProviderSyncRunFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\RecipientSuppressionFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\RecipientSuppressionFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\SenderIdentityFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\SenderIdentityFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\SendingDomainFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\SendingDomainFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\StagedMailFileFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\StagedMailFileFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Factories\\WebhookReceiptFactory' => 'Nvl\\MailNotifications\\Database\\Factories\\WebhookReceiptFactory',
        'Nvl\\MailNotifications\\Managed\\Database\\Seeders\\IntegrationSeeder' => 'Nvl\\MailNotifications\\Database\\Seeders\\IntegrationSeeder',
        'Nvl\\MailNotifications\\Managed\\Enums\\AccountStatus' => 'Nvl\\MailNotifications\\Enums\\AccountStatus',
        'Nvl\\MailNotifications\\Managed\\Enums\\ClientAuthenticationMode' => 'Nvl\\MailNotifications\\Enums\\ClientAuthenticationMode',
        'Nvl\\MailNotifications\\Managed\\Enums\\EmailFlagType' => 'Nvl\\MailNotifications\\Enums\\EmailFlagType',
        'Nvl\\MailNotifications\\Managed\\Enums\\EmailMessageType' => 'Nvl\\MailNotifications\\Enums\\EmailMessageType',
        'Nvl\\MailNotifications\\Managed\\Enums\\ProviderSyncStatus' => 'Nvl\\MailNotifications\\Enums\\ProviderSyncStatus',
        'Nvl\\MailNotifications\\Managed\\Jobs\\DeliverClientWebhook' => 'Nvl\\MailNotifications\\Jobs\\DeliverClientWebhook',
        'Nvl\\MailNotifications\\Managed\\Jobs\\ProcessProviderSyncPage' => 'Nvl\\MailNotifications\\Jobs\\ProcessProviderSyncPage',
        'Nvl\\MailNotifications\\Managed\\Jobs\\ProcessProviderWebhook' => 'Nvl\\MailNotifications\\Jobs\\ProcessProviderWebhook',
        'Nvl\\MailNotifications\\Managed\\Jobs\\SendEmailMessage' => 'Nvl\\MailNotifications\\Jobs\\SendEmailMessage',
        'Nvl\\MailNotifications\\Managed\\Models\\Asset' => 'Nvl\\MailNotifications\\Models\\Asset',
        'Nvl\\MailNotifications\\Managed\\Models\\Client' => 'Nvl\\MailNotifications\\Models\\Client',
        'Nvl\\MailNotifications\\Managed\\Models\\ClientApiKey' => 'Nvl\\MailNotifications\\Models\\ClientApiKey',
        'Nvl\\MailNotifications\\Managed\\Models\\ClientMailSettings' => 'Nvl\\MailNotifications\\Models\\ClientMailSettings',
        'Nvl\\MailNotifications\\Managed\\Models\\ClientWebhookDelivery' => 'Nvl\\MailNotifications\\Models\\ClientWebhookDelivery',
        'Nvl\\MailNotifications\\Managed\\Models\\ClientWebhookEndpoint' => 'Nvl\\MailNotifications\\Models\\ClientWebhookEndpoint',
        'Nvl\\MailNotifications\\Managed\\Models\\EmailAttempt' => 'Nvl\\MailNotifications\\Models\\EmailAttempt',
        'Nvl\\MailNotifications\\Managed\\Models\\EmailEvent' => 'Nvl\\MailNotifications\\Models\\EmailEvent',
        'Nvl\\MailNotifications\\Managed\\Models\\EmailFlagRule' => 'Nvl\\MailNotifications\\Models\\EmailFlagRule',
        'Nvl\\MailNotifications\\Managed\\Models\\EmailMessage' => 'Nvl\\MailNotifications\\Models\\EmailMessage',
        'Nvl\\MailNotifications\\Managed\\Models\\EmailMessageFlag' => 'Nvl\\MailNotifications\\Models\\EmailMessageFlag',
        'Nvl\\MailNotifications\\Managed\\Models\\EmailMessageReference' => 'Nvl\\MailNotifications\\Models\\EmailMessageReference',
        'Nvl\\MailNotifications\\Managed\\Models\\EmailRecipient' => 'Nvl\\MailNotifications\\Models\\EmailRecipient',
        'Nvl\\MailNotifications\\Managed\\Models\\Integration' => 'Nvl\\MailNotifications\\Models\\Integration',
        'Nvl\\MailNotifications\\Managed\\Models\\MarketingOptOut' => 'Nvl\\MailNotifications\\Models\\MarketingOptOut',
        'Nvl\\MailNotifications\\Managed\\Models\\MessageAsset' => 'Nvl\\MailNotifications\\Models\\MessageAsset',
        'Nvl\\MailNotifications\\Managed\\Models\\Owner' => 'Nvl\\MailNotifications\\Models\\Owner',
        'Nvl\\MailNotifications\\Managed\\Models\\OwnerMailSettings' => 'Nvl\\MailNotifications\\Models\\OwnerMailSettings',
        'Nvl\\MailNotifications\\Managed\\Models\\ProviderAccount' => 'Nvl\\MailNotifications\\Models\\ProviderAccount',
        'Nvl\\MailNotifications\\Managed\\Models\\ProviderSyncRun' => 'Nvl\\MailNotifications\\Models\\ProviderSyncRun',
        'Nvl\\MailNotifications\\Managed\\Models\\RecipientSuppression' => 'Nvl\\MailNotifications\\Models\\RecipientSuppression',
        'Nvl\\MailNotifications\\Managed\\Models\\SenderIdentity' => 'Nvl\\MailNotifications\\Models\\SenderIdentity',
        'Nvl\\MailNotifications\\Managed\\Models\\SendingDomain' => 'Nvl\\MailNotifications\\Models\\SendingDomain',
        'Nvl\\MailNotifications\\Managed\\Models\\StagedMailFile' => 'Nvl\\MailNotifications\\Models\\StagedMailFile',
        'Nvl\\MailNotifications\\Managed\\Models\\WebhookReceipt' => 'Nvl\\MailNotifications\\Models\\WebhookReceipt',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\AssetManager' => 'Nvl\\MailNotifications\\Services\\Delivery\\AssetManager',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\AttachmentLocalizer' => 'Nvl\\MailNotifications\\Services\\Delivery\\AttachmentLocalizer',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\AttachmentRetention' => 'Nvl\\MailNotifications\\Services\\Delivery\\AttachmentRetention',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\ClientWebhookPublisher' => 'Nvl\\MailNotifications\\Services\\Delivery\\ClientWebhookPublisher',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\ClientWebhookSender' => 'Nvl\\MailNotifications\\Services\\Delivery\\ClientWebhookSender',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\ClientWebhookTarget' => 'Nvl\\MailNotifications\\Services\\Delivery\\ClientWebhookTarget',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\EmailContent' => 'Nvl\\MailNotifications\\Services\\Delivery\\EmailContent',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\MailMediaAuthorization' => 'Nvl\\MailNotifications\\Services\\Delivery\\MailMediaAuthorization',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\MailMediaStorage' => 'Nvl\\MailNotifications\\Services\\Delivery\\MailMediaStorage',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\MailTesting' => 'Nvl\\MailNotifications\\Services\\Delivery\\MailTesting',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\MessageAcceptor' => 'Nvl\\MailNotifications\\Services\\Delivery\\MessageAcceptor',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\MessageDispatcher' => 'Nvl\\MailNotifications\\Services\\Delivery\\MessageDispatcher',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\MessageMimeBuilder' => 'Nvl\\MailNotifications\\Services\\Delivery\\MessageMimeBuilder',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\MessagePreview' => 'Nvl\\MailNotifications\\Services\\Delivery\\MessagePreview',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\MessageSender' => 'Nvl\\MailNotifications\\Services\\Delivery\\MessageSender',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\MessageStatusProjector' => 'Nvl\\MailNotifications\\Services\\Delivery\\MessageStatusProjector',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\ProviderManager' => 'Nvl\\MailNotifications\\Services\\Delivery\\ProviderManager',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\ProviderPayloadLimit' => 'Nvl\\MailNotifications\\Services\\Delivery\\ProviderPayloadLimit',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\ProviderResult' => 'Nvl\\MailNotifications\\Services\\Delivery\\ProviderResult',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\ProviderRetryDelay' => 'Nvl\\MailNotifications\\Services\\Delivery\\ProviderRetryDelay',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\ProviderSendingAllowance' => 'Nvl\\MailNotifications\\Services\\Delivery\\ProviderSendingAllowance',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\ProviderWebhookAdapter' => 'Nvl\\MailNotifications\\Services\\Delivery\\ProviderWebhookAdapter',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\Providers\\MailerSendProvider' => 'Nvl\\MailNotifications\\Services\\Delivery\\Providers\\MailerSendProvider',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\Providers\\MailgunProvider' => 'Nvl\\MailNotifications\\Services\\Delivery\\Providers\\MailgunProvider',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\Providers\\SendGridProvider' => 'Nvl\\MailNotifications\\Services\\Delivery\\Providers\\SendGridProvider',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\Providers\\SmtpProvider' => 'Nvl\\MailNotifications\\Services\\Delivery\\Providers\\SmtpProvider',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\PublicAddressResolver' => 'Nvl\\MailNotifications\\Services\\Delivery\\PublicAddressResolver',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\RemoteAttachmentFetcher' => 'Nvl\\MailNotifications\\Services\\Delivery\\RemoteAttachmentFetcher',
        'Nvl\\MailNotifications\\Managed\\Services\\Delivery\\WebhookProcessor' => 'Nvl\\MailNotifications\\Services\\Delivery\\WebhookProcessor',
        'Nvl\\MailNotifications\\Managed\\Services\\Flags\\EmailFlagger' => 'Nvl\\MailNotifications\\Services\\Flags\\EmailFlagger',
        'Nvl\\MailNotifications\\Managed\\Services\\Integrations\\HttpProviderApiAdapter' => 'Nvl\\MailNotifications\\Services\\Integrations\\HttpProviderApiAdapter',
        'Nvl\\MailNotifications\\Managed\\Services\\Integrations\\InspectProviderMessage' => 'Nvl\\MailNotifications\\Services\\Integrations\\InspectProviderMessage',
        'Nvl\\MailNotifications\\Managed\\Services\\Integrations\\ProviderApiManager' => 'Nvl\\MailNotifications\\Services\\Integrations\\ProviderApiManager',
        'Nvl\\MailNotifications\\Managed\\Services\\Integrations\\ProviderApiResult' => 'Nvl\\MailNotifications\\Services\\Integrations\\ProviderApiResult',
        'Nvl\\MailNotifications\\Managed\\Services\\Integrations\\SyncProviderConfiguration' => 'Nvl\\MailNotifications\\Services\\Integrations\\SyncProviderConfiguration',
        'Nvl\\MailNotifications\\Managed\\Services\\MailFilePruner' => 'Nvl\\MailNotifications\\Services\\MailFilePruner',
        'Nvl\\MailNotifications\\Managed\\Services\\MailHistoryPruner' => 'Nvl\\MailNotifications\\Services\\MailHistoryPruner',
        'Nvl\\MailNotifications\\Managed\\Services\\MailSettingsResolver' => 'Nvl\\MailNotifications\\Services\\MailSettingsResolver',
        'Nvl\\MailNotifications\\Managed\\Services\\MailablePreparation' => 'Nvl\\MailNotifications\\Services\\MailablePreparation',
        'Nvl\\MailNotifications\\Managed\\Services\\ManagedDelivery' => 'Nvl\\MailNotifications\\Services\\ManagedDelivery',
        'Nvl\\MailNotifications\\Managed\\Services\\ManagedDoctor' => 'Nvl\\MailNotifications\\Services\\ManagedDoctor',
        'Nvl\\MailNotifications\\Managed\\Services\\MessageInputValidator' => 'Nvl\\MailNotifications\\Services\\MessageInputValidator',
        'Nvl\\MailNotifications\\Managed\\Support\\MailMediaSlots' => 'Nvl\\MailNotifications\\Support\\MailMediaSlots',
        'Nvl\\MailNotifications\\Managed\\Support\\ManagedConfiguration' => 'Nvl\\MailNotifications\\Support\\ManagedConfiguration',
        'Nvl\\MailNotifications\\Managed\\Support\\SenderDomainMatchExpression' => 'Nvl\\MailNotifications\\Support\\SenderDomainMatchExpression',
        'Nvl\\MailNotifications\\Managed\\Support\\UsesManagedStorage' => 'Nvl\\MailNotifications\\Support\\UsesManagedStorage',
    ];
}

/**
 * Alias a recorded identity on first use without loading the package at boot.
 *
 * PHP instanceof and parameter checks never autoload, so the alias is also
 * registered the moment its canonical implementation is loaded.
 */
$canonicalByPrevious = legacyManagedNamespaces();
$previousByCanonical = array_flip($canonicalByPrevious);

spl_autoload_register(static function (string $class) use ($canonicalByPrevious, $previousByCanonical): void {
    $declared = static fn (string $name, bool $autoload): bool => class_exists($name, $autoload)
        || interface_exists($name, $autoload)
        || trait_exists($name, $autoload);

    if (isset($canonicalByPrevious[$class])) {
        $canonical = $canonicalByPrevious[$class];

        if ($declared($canonical, true) && ! $declared($class, false)) {
            class_alias($canonical, $class);
        }

        return;
    }

    if (! isset($previousByCanonical[$class])) {
        return;
    }

    foreach (ClassLoader::getRegisteredLoaders() as $loader) {
        if ($loader->loadClass($class) === true) {
            break;
        }
    }

    $previous = $previousByCanonical[$class];

    if ($declared($class, false) && ! $declared($previous, false)) {
        class_alias($class, $previous);
    }
}, true, true);
