<?php $titel = 'Einrichtung' ?>
<form method="post" class="karte schmal">
  <h1>Ersten Admin anlegen</h1>
  <?= csrf_feld() ?><input type="hidden" name="do" value="setup">
  <label>Einrichtungspasswort (aus config.php)<input type="password" name="initpw" required></label>
  <label>Benutzername<input name="name" required autocomplete="username"></label>
  <label>Voller Name<input name="fullname"></label>
  <label>Passwort (mind. 8 Zeichen)<input type="password" name="pw" required autocomplete="new-password"></label>
  <label>Passwort wiederholen<input type="password" name="pw2" required autocomplete="new-password"></label>
  <button class="knopf haupt">Admin anlegen</button>
</form>
