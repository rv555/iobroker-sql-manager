<?php
// Kurzer Einstieg in die Berichtsansicht: berichte.php (optional ?r=Nummer des Reports)
$r = (int)($_GET['r'] ?? 0);
header('Location: index.php?s=berichte' . ($r ? '&r=' . $r : ''));
