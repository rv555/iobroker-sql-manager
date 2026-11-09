<?php
// Benutzerverwaltung (nur Admin): Admin darf alles, Leser nur ansehen
$titel = 'Benutzer';
$liste = all('SELECT * FROM users ORDER BY name');
?>
<h1>Benutzer</h1>
<table class="liste">
<tr><th>Login</th><th>Name</th><th>Rolle</th><th>Status</th><th>Letzte Anmeldung</th><th></th></tr>
<?php foreach ($liste as $b): $self = (int)$b['id'] === (int)$u['id']; ?>
<tr>
  <td><?= h($b['name']) ?></td>
  <td colspan="3">
    <form method="post" class="zeile eng"><?= csrf_feld() ?><input type="hidden" name="do" value="user_save"><input type="hidden" name="id" value="<?= $b['id'] ?>">
      <input name="fullname" value="<?= h($b['fullname']) ?>" placeholder="Voller Name">
      <label class="haken"><input type="checkbox" name="admin" <?= $b['admin'] ? 'checked' : '' ?> <?= $self ? 'disabled' : '' ?>> Admin</label>
      <label class="haken"><input type="checkbox" name="active" <?= $b['active'] ? 'checked' : '' ?> <?= $self ? 'disabled' : '' ?>> aktiv</label>
      <span class="leise"><?= $b['pwneu'] ? 'Startpasswort' : '' ?></span>
      <button class="knopf klein">Speichern</button>
    </form>
  </td>
  <td><?= $b['last_login'] ? utc_zeit($b['last_login']) : '<span class="leise">noch nie</span>' ?></td>
  <td class="rechts">
    <form method="post" class="inline"><?= csrf_feld() ?><input type="hidden" name="id" value="<?= $b['id'] ?>">
      <button class="knopf klein" name="do" value="user_pw" data-confirm="Neues Startpasswort für <?= h($b['name']) ?> erzeugen?">Passwort zurücksetzen</button>
      <?php if (!$self): ?><button class="knopf klein gefahr" name="do" value="user_del" data-confirm="Benutzer <?= h($b['name']) ?> löschen?">Löschen</button><?php endif ?>
    </form>
  </td>
</tr>
<?php endforeach ?>
</table>
<p class="leise">Admin: alles. Ohne Admin-Haken (Leser): Übersicht, Diagramme, Reports und Protokoll nur ansehen.</p>
<form method="post" class="zeile"><?= csrf_feld() ?><input type="hidden" name="do" value="user_add">
  <input name="name" placeholder="Login" required><input name="fullname" placeholder="Voller Name">
  <label class="haken"><input type="checkbox" name="admin"> Admin</label>
  <button class="knopf">Benutzer anlegen</button></form>
