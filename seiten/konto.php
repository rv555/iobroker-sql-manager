<?php $titel = 'Passwort' ?>
<form method="post" class="karte schmal">
  <h1><?= $u['pwneu'] ? 'Eigenes Passwort festlegen' : 'Passwort ändern' ?></h1>
  <?php if ($u['pwneu']): ?><p class="leise">Bitte vor der ersten Nutzung ein eigenes Passwort setzen.</p><?php endif ?>
  <?= csrf_feld() ?><input type="hidden" name="do" value="pw">
  <?php if (!$u['pwneu']): ?><label>Bisheriges Passwort<input type="password" name="alt" required autocomplete="current-password"></label><?php endif ?>
  <label>Neues Passwort (mind. 8 Zeichen)<input type="password" name="pw" required autocomplete="new-password"></label>
  <label>Wiederholen<input type="password" name="pw2" required autocomplete="new-password"></label>
  <button class="knopf haupt">Speichern</button>
</form>
