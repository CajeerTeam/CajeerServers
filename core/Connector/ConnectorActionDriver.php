<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Optional trusted driver capability. Action data is never used as discovery observations. */
interface ConnectorActionDriver
{
    public function actions(): array;
    public function prepareAction(ConnectorContext $context,string $action,string $externalId,#[\SensitiveParameter] string $secret): array;
    public function dispatchAction(ConnectorContext $context,string $action,array $resource,#[\SensitiveParameter] string $secret): string;
    public function actionStatus(ConnectorContext $context,string $node,string $task,#[\SensitiveParameter] string $secret): string;
}
