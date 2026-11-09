<?php
// Passwort vergessen (nur Admins): neues Passwort mit dem Einrichtungspasswort aus config.php setzen
$titel = 'Passwort vergessen';
$aktiv = notfall_moeglich();
?>
<form method="post" class="karte schmal">
  <h1>Passwort vergessen</h1>
  <?php if (!$aktiv): ?>
    <p>Der Notfall-Zugang ist nicht eingerichtet: In der <code>config.php</code> auf dem Server fehlt ein eigenes Einrichtungspasswort (<code>initpw</code>).</p>
  <?php else: ?>
  <p class="leise">Für Admins. Das Einrichtungspasswort steht in der <code>config.php</code> auf dem Server (<code>initpw</code>). Benutzer ohne Admin-Rechte wenden sich an einen Admin, der ihr Passwort unter „Benutzer“ zurücksetzt.</p>
  <?= csrf_feld() ?><input type="hidden" name="do" value="notfall">
  <label>Anmeldename (wie beim Login, nicht der volle Name)<input name="name" required autofocus autocomplete="username"></label>
  <label>Einrichtungspasswort<input type="password" name="initpw" required autocomplete="off"></label>
  <label>Neues Passwort (mind. 8 Zeichen)<input type="password" name="pw" required autocomplete="new-password"></label>
  <label>Neues Passwort wiederholen<input type="password" name="pw2" required autocomplete="new-password"></label>
  <button class="knopf haupt">Passwort setzen und anmelden</button>
  <?php endif ?>
  <p><a href="index.php">‹ zur Anmeldung</a></p>
</form>
