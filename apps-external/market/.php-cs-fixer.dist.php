<?php
/**
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-06-16.
 * Changes:
 *   - bundle the market app (neutral, local catalog default)
 */
 $config = new OC\CodingStandard\Config();
 $config
    ->setUsingCache(true)
    ->getFinder()
    ->exclude('vendor')
    ->exclude('vendor-bin')
    ->exclude('l10n')
    ->in(__DIR__);
 return $config;
