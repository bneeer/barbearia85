<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $title }}</title></head>
<body style="margin:0;padding:24px 12px;background:#0b0b0b;color:#f4f0e5;font-family:Arial,Helvetica,sans-serif">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="24" cellspacing="0" style="max-width:600px;background:#151515;border:1px solid #3b3528;border-radius:16px">
<tr><td style="color:#d3b04c;font-weight:bold;font-size:24px">BARBEARIA 85</td></tr>
<tr><td>
<h1 style="font-size:26px">{{ $title }}</h1>
<p>Recebemos uma solicitação para consultar seus agendamentos. Nenhum horário foi cancelado. Se não foi você, ignore esta mensagem.</p>
<p>Os links são válidos até {{ $expiresAt }}. Não compartilhe este e-mail. A abertura do link apenas exibe uma confirmação.</p>
@foreach($appointments as $appointment)
<div style="padding:20px;margin:20px 0;background:#242424;border-radius:8px;line-height:1.6">
<h2 style="font-size:20px">{{ $appointment['service'] }}</h2>
<p>Unidade: {{ $appointment['barbershop'] }}<br>
Profissional: {{ $appointment['barber'] }}<br>
Horário: {{ $appointment['starts_at'] }} ({{ $appointment['timezone'] }})<br>
Prazo para cancelar: {{ $appointment['deadline'] }} ({{ $appointment['timezone'] }})</p>
@if($appointment['reason'])
<p>{{ $appointment['reason'] }}</p>
@elseif(isset($links[$appointment['id']]))
<p><a href="{{ $links[$appointment['id']] }}" style="display:inline-block;background:#d3b04c;color:#16120a;padding:14px 20px;border-radius:8px;text-decoration:none;font-weight:bold">Cancelar este agendamento</a></p>
<p style="font-size:12px;word-break:break-all">Se o botão não funcionar: {{ $links[$appointment['id']] }}</p>
@endif
@if($appointment['phones'])<p>Contato da unidade: {{ implode(' · ', $appointment['phones']) }}</p>@endif
</div>
@endforeach
@if(isset($links['all']))
<p><a href="{{ $links['all'] }}" style="display:inline-block;background:#d3b04c;color:#16120a;padding:16px 24px;border-radius:8px;text-decoration:none;font-weight:bold">Cancelar todos os agendamentos</a></p>
<p style="font-size:12px;word-break:break-all">Se o botão não funcionar: {{ $links['all'] }}</p>
<p>O cancelamento de todos só será realizado se todos os agendamentos listados ainda forem elegíveis na confirmação.</p>
@endif
<p>O prazo de cada unidade será verificado novamente ao confirmar o cancelamento.</p>
</td></tr>
<tr><td style="font-size:12px;color:#c1b9a7">Barbearia 85 · Mensagem automática. Não responder.</td></tr>
</table>
</td></tr></table>
</body>
</html>
