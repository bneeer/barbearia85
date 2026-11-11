<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $title }}</title></head>
<body style="margin:0;padding:24px 12px;background:#0b0b0b;color:#f4f0e5;font-family:Arial,Helvetica,sans-serif">
<div style="display:none;max-height:0;overflow:hidden">{{ $description }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#151515;border:1px solid #3b3528;border-radius:16px">
<tr><td style="padding:32px;border-bottom:1px solid #3b3528;color:#d3b04c;font-weight:bold;font-size:24px;letter-spacing:3px">BARBEARIA 85</td></tr>
<tr><td style="padding:32px">
<p style="color:#c1b9a7">Olá, {{ $audience === 'client' || $account ? ($details['name'] ?? '') : 'equipe' }}!</p>
<h1 style="font-size:28px;line-height:1.25;color:#f4f0e5">{{ $title }}</h1>
<p style="line-height:1.7;color:#f4f0e5">{{ $description }}</p>
@unless($account)
<table role="presentation" width="100%" cellpadding="10" cellspacing="0" style="background:#242424;border-radius:8px;line-height:1.5;color:#f4f0e5">
@if($audience !== 'client')<tr><td>Cliente</td><td>{{ $details['name'] }}</td></tr>@endif
<tr><td>Unidade</td><td>{{ $details['barbershop'] }}</td></tr>
<tr><td>Serviços</td><td>{{ $details['service'] }}</td></tr>
<tr><td>Profissional</td><td>{{ $details['barber'] }}</td></tr>
@if($previousStartsAt)<tr><td>Horário anterior</td><td>{{ $previousStartsAt }}</td></tr>@endif
<tr><td>Horário</td><td>{{ $startsAt }}<br><small>{{ $details['timezone'] }}</small></td></tr>
<tr><td>Duração</td><td>{{ $details['duration'] }} minutos</td></tr>
<tr><td>Endereço</td><td>{{ $details['address'] }}</td></tr>
@if($audience === 'client' && isset($details['price']))<tr><td>Valor dos serviços</td><td>R$ {{ number_format($details['price'], 2, ',', '.') }}</td></tr>@endif
</table>
@if(!empty($details['phones']))<p style="color:#c1b9a7">Fale com a unidade: {{ implode(' · ', $details['phones']) }}</p>@endif
@endunless
@if($actionUrl)
<p style="margin:32px 0"><a href="{{ $actionUrl }}" style="display:inline-block;background:#d3b04c;color:#16120a;padding:16px 24px;border-radius:8px;text-decoration:none;font-weight:bold">{{ $actionLabel }}</a></p>
@if($account)<p style="font-size:12px;color:#c1b9a7;word-break:break-all">Se o botão não funcionar, copie este link: {{ $actionUrl }}</p>@endif
@endif
@if($account && $actionUrl)<p style="font-size:13px;color:#c1b9a7">Link válido até {{ $expiresAt }}. Não compartilhe este e-mail.</p>@endif
</td></tr>
<tr><td style="padding:24px 32px;border-top:1px solid #3b3528;font-size:12px;color:#c1b9a7">Barbearia 85 · A tradição do corte, a modernidade do estilo.<br>Mensagem automática. Não responder.</td></tr>
</table>
</td></tr></table>
</body>
</html>
