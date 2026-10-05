<?php /** The expense's trail (without the screen views). Data: rows, tz */ ?>
<?= view('shared/timeline.php', ['rows' => $rows, 'tz' => $tz, 'prefix' => 'expense']) ?>
