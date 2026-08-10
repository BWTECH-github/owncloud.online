<?php

/**
 * Phan baseline for owncloud.online (PHP 8.4 fork).
 *
 * Suppresses pre-existing, well-understood false positives that surface only
 * because the legacy ownCloud 10.x code base is analysed against PHP 8.4. None
 * of these are regressions introduced by this fork; each is either a Symfony
 * Console runtime type that Phan cannot resolve statically, a ReflectionType
 * narrowing Phan does not follow, or a never-reached legacy sentinel path.
 *
 * Suppressions are scoped per file AND per issue type, so any NEW issue of a
 * different type in these files is still reported.
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-06-24.
 * Changes:
 *   - baseline pre-existing PHP 8.4 false positives
 */
return [
	'file_suppressions' => [
		// $this->getHelper('question') returns a QuestionHelper at runtime,
		// which has ask(); the declared HelperInterface does not.
		'apps/files/lib/Command/TransferOwnership.php' => ['PhanUndeclaredMethod'],
		'apps/files_external/lib/Command/Delete.php' => ['PhanUndeclaredMethod'],
		'core/Command/Security/CreateSignKey.php' => ['PhanUndeclaredMethod'],
		'core/Command/User/SyncBackend.php' => ['PhanUndeclaredMethod'],
		// ReflectionParameter::getType() is a single ReflectionNamedType for
		// constructor injection at runtime; isBuiltin()/getName() exist there.
		'lib/private/AppFramework/Utility/SimpleContainer.php' => ['PhanUndeclaredMethod'],
		// ICacheFactory::createLocal() exists on the concrete factory.
		'lib/private/L10N/L10N.php' => ['PhanUndeclaredMethod'],
		// Legacy BMP RLE8 encoder: the string sentinel "\0" never reaches
		// chr() because of the $sameNum != 0 guard; changing it to int 0 would
		// alter encoding for colour index 0.
		'lib/private/legacy/image.php' => ['PhanTypeMismatchArgumentInternalReal'],
	],
];
