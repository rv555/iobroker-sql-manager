<?php $titel = 'Anmelden';
$zurueck = $_SERVER['QUERY_STRING'] ?? ''; ?>
<form method="post" class="karte schmal">
  <h1>Anmelden</h1>
  <?= csrf_feld() ?><input type="hidden" name="do" value="login">
  <input type="hidden" name="zurueck" value="<?= h($zurueck) ?>">
  <label>Benutzername<input name="name" required autofocus autocomplete="username"></label>
  <label>Passwort<input type="password" name="pw" required autocomplete="current-password"></label>
  <label class="haken"><input type="checkbox" name="merken" checked> Angemeldet bleiben (<?= MERKEN_TAGE ?> Tage, auf eigenen Geräten)</label>
  <button class="knopf haupt">Anmelden</button>
  <p class="leise klein"><a href="?s=notfall">Passwort vergessen?</a></p>
</form>
