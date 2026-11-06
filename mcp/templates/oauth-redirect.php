<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
/** @var array $_ The target is built only from a revalidated OAuth request. */
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta http-equiv="refresh" content="0;url=<?php p($_['target']); ?>">
    <title>MCP for Cloud</title>
</head>
<body>
    <p><a href="<?php p($_['target']); ?>" rel="noreferrer"><?php p($l->t('Connect')); ?></a></p>
</body>
</html>
