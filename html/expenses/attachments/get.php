<?php
declare(strict_types=1);
/** /expenses/attachments/get.php?id= — a receipt through the gate: the same door as /attachments/{id} (the mcp_attachments view decides; nosniff; inline for images and PDFs; never served by Apache from storage/). */
require dirname(__DIR__, 2) . '/attachments/view.php';
