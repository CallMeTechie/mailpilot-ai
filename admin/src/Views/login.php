<?php /** @var ?string $error */ ?>
<div class="login-card">
	<h1>MailPilot Admin</h1>
	<p class="muted">Zugang nur für Administratoren.</p>

	<?php if ($error): ?>
		<div class="flash flash-error">
			<?= $error === 'invalid' ? 'Benutzername oder Passwort falsch.'
				: ($error === 'ip_blocked' ? 'Zugriff von dieser IP nicht erlaubt.'
				: ($error === 'locked' ? 'Zu viele Fehlversuche von dieser IP. Bitte in einigen Minuten erneut versuchen.'
				: 'Fehler')) ?>
		</div>
	<?php endif; ?>

	<form method="POST" action="/admin/login" autocomplete="off">
		<label>
			<span>Benutzername</span>
			<input type="text" name="username" required autofocus>
		</label>
		<label>
			<span>Passwort</span>
			<input type="password" name="password" required>
		</label>
		<button type="submit" class="btn btn-primary">Anmelden</button>
	</form>
</div>
