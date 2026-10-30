<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light only">
<title>Seu contrato assinado · São Francisco</title>
</head>
<body style="margin:0;padding:0;background:#f4f1ed;font-family:Arial,Helvetica,sans-serif;color:#14264b;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">Pagamento confirmado. Seu contrato assinado está em anexo.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1ed;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:18px;overflow:hidden;">
  <tr><td align="center" style="background:#ffffff;padding:28px 24px 20px;">
    <img src="{{ isset($message) ? $message->embed(public_path('assets/brand/sao-francisco-logo.png')) : $site.'/imgs/logo.png' }}" width="150" height="104" alt="São Francisco" style="display:block;border:0;height:auto;margin:0 auto;">
  </td></tr>
  <tr><td align="center" style="background:#0b1936;padding:12px 24px;">
    <p style="margin:0;font-size:12px;letter-spacing:2px;color:#a7cd5d;font-weight:bold;">PAGAMENTO CONFIRMADO</p>
  </td></tr>
  <tr><td style="padding:30px 32px 8px;">
    <h1 style="margin:0 0 12px;font-size:24px;line-height:1.25;color:#0b1936;">Olá, {{ $name }}!</h1>
    <p style="margin:0 0 8px;font-size:16px;line-height:1.6;color:#33465f;">Seu contrato assinado de <strong>{{ $plan }}</strong> para <strong>{{ $pet }}</strong> está em anexo.</p>
  </td></tr>
  <tr><td align="center" style="padding:18px 32px 10px;">
    <a href="{{ $url }}" style="display:inline-block;background:#83ac38;color:#0b1936;font-size:17px;font-weight:bold;text-decoration:none;padding:15px 34px;border-radius:12px;">Abrir minha área</a>
  </td></tr>
  <tr><td style="padding:12px 32px 28px;">
    <p style="margin:0;font-size:14px;line-height:1.6;color:#5c6f85;text-align:center;">O contrato também fica guardado na sua área do cliente.</p>
  </td></tr>
  <tr><td align="center" style="background:#f8f5f1;padding:18px 24px;border-top:1px solid #eee7e0;">
    <p style="margin:0;font-size:12px;line-height:1.6;color:#7a8797;"><strong style="color:#0b1936;">São Francisco</strong> · Cuidado em cada fase da vida<br>Telefone: (18) 3221-2021</p>
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
