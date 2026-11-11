<?php
// Gera o hash da senha da área do dono.
// Uso:  php tools/gerar-senha.php            (pede a senha sem mostrar na tela)
// Cole o resultado em 'admin_password_hash' no config.php, entre aspas simples.
if (PHP_SAPI !== 'cli') {
    exit('Execute pelo terminal.');
}
$pw = $argv[1] ?? null;
if ($pw === null) {
    fwrite(STDERR, 'Digite a nova senha (mínimo 8 caracteres): ');
    @shell_exec('stty -echo 2>/dev/null');
    $pw = rtrim((string)fgets(STDIN), "\r\n");
    @shell_exec('stty echo 2>/dev/null');
    fwrite(STDERR, "\n");
}
if (strlen($pw) < 8) {
    fwrite(STDERR, "A senha precisa ter pelo menos 8 caracteres.\n");
    exit(1);
}
echo password_hash($pw, PASSWORD_DEFAULT), "\n";
