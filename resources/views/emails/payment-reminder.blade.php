<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light only">
<title>Falta só o pagamento · São Francisco</title>
</head>
<body style="margin:0;padding:0;background:#f4f1ed;font-family:Arial,Helvetica,sans-serif;color:#14264b;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">Falta só o pagamento da entrada para o plano de {{ $pet }} começar a valer.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1ed;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:18px;overflow:hidden;">
  <tr><td align="center" style="background:#ffffff;padding:28px 24px 20px;">
    <img src="{{ isset($message) ? $message->embed(public_path('assets/brand/sao-francisco-logo.png')) : $site.'/imgs/logo.png' }}" width="150" height="104" alt="São Francisco" style="display:block;border:0;height:auto;margin:0 auto;">
  </td></tr>
  <tr><td align="center" style="background:#0b1936;padding:12px 24px;">
    <p style="margin:0;font-size:12px;letter-spacing:2px;color:#a7cd5d;font-weight:bold;">FALTA SÓ UM PASSO</p>
  </td></tr>
  <tr><td style="padding:30px 32px 8px;">
    <h1 style="margin:0 0 12px;font-size:24px;line-height:1.25;color:#0b1936;">Olá, {{ $name }}!</h1>
    <p style="margin:0 0 8px;font-size:16px;line-height:1.6;color:#33465f;">Você assinou o plano de <strong style="color:#0b1936;">{{ $pet }}</strong>. Agora falta só o <strong style="color:#0b1936;">pagamento da entrada</strong> para ele começar a valer.</p>
  </td></tr>
  <tr><td style="padding:14px 32px 4px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f5f1;border-radius:12px;">
      <tr><td style="padding:14px 18px 6px;font-size:13px;color:#7a8797;">Plano</td><td align="right" style="padding:14px 18px 6px;font-size:15px;font-weight:bold;color:#0b1936;">{{ $plan }}</td></tr>
      <tr><td style="padding:6px 18px;font-size:13px;color:#7a8797;">Pet</td><td align="right" style="padding:6px 18px;font-size:15px;font-weight:bold;color:#0b1936;">{{ $pet }}</td></tr>
      @if($amount)<tr><td style="padding:6px 18px 14px;font-size:13px;color:#7a8797;">Entrada</td><td align="right" style="padding:6px 18px 14px;font-size:15px;font-weight:bold;color:#0b1936;">{{ $amount }}</td></tr>@endif
    </table>
  </td></tr>
  @if($freeMonths)
  <tr><td style="padding:14px 32px 4px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef6df;border:1px solid #cfe3a6;border-radius:12px;"><tr><td style="padding:16px 18px;">
      <p style="margin:0 0 4px;font-size:12px;letter-spacing:1.5px;color:#5b7f1f;font-weight:bold;">SUA PROMOÇÃO</p>
      <p style="margin:0;font-size:15px;line-height:1.55;color:#0b1936;"><strong>{{ $campaign }}</strong>: {{ $freeMonths }} {{ $freeMonths === 1 ? 'mensalidade grátis' : 'mensalidades grátis' }} depois da entrada. Pague a entrada para garantir.</p>
    </td></tr></table>
  </td></tr>
  @endif
  <tr><td align="center" style="padding:22px 32px 10px;">
    <a href="{{ $url }}" style="display:inline-block;background:#83ac38;color:#0b1936;font-size:17px;font-weight:bold;text-decoration:none;padding:15px 34px;border-radius:12px;">Pagar a entrada</a>
  </td></tr>
  <tr><td style="padding:8px 32px 4px;">
    <p style="margin:0;font-size:14px;line-height:1.6;color:#5c6f85;text-align:center;">Não precisa de senha: para sua segurança, vamos pedir só o <strong>CPF do titular</strong>.</p>
  </td></tr>
  <tr><td style="padding:22px 32px 8px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef6fa;border-radius:12px;"><tr><td style="padding:14px 16px;font-size:13px;line-height:1.6;color:#4f6b7d;">
      O botão não abre? Copie e cole este endereço no navegador:<br>
      <span style="word-break:break-all;color:#0b4f7d;">{{ $url }}</span>
    </td></tr></table>
  </td></tr>
  <tr><td style="padding:18px 32px 28px;">
    <p style="margin:0;font-size:13px;line-height:1.6;color:#7a8797;">Já pagou? É só ignorar esta mensagem: a confirmação pode levar alguns minutos para aparecer. Ficou com dúvida? Fale com a gente pelo telefone abaixo.</p>
  </td></tr>
  <tr><td align="center" style="background:#f8f5f1;padding:18px 24px;border-top:1px solid #eee7e0;">
    <p style="margin:0;font-size:12px;line-height:1.6;color:#7a8797;"><strong style="color:#0b1936;">São Francisco</strong> · Cuidado em cada fase da vida<br>Telefone: (18) 3221-2021</p>
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
