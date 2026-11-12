<?php
declare(strict_types=1);
namespace Cajeer\ServersSdk;
/** Metric-capable trusted custom connector, using the production metric batch contract. */
interface MetricConnector extends Connector, \Cajeer\Servers\Metrics\MetricDriver {}
