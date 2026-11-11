BARBEARIA 85

{{ $title }}

{{ $description }}

@unless($account)
Cliente: {{ $details['name'] }}
Unidade: {{ $details['barbershop'] }}
Serviços: {{ $details['service'] }}
Profissional: {{ $details['barber'] }}
@if($previousStartsAt)
Horário anterior: {{ $previousStartsAt }}
@endif
Horário: {{ $startsAt }} ({{ $details['timezone'] }})
Duração: {{ $details['duration'] }} minutos
Endereço: {{ $details['address'] }}
@if(!empty($details['phones']))
Contato: {{ implode(' · ', $details['phones']) }}
@endif
@if($audience === 'client' && isset($details['price']))
Valor dos serviços: R$ {{ number_format($details['price'], 2, ',', '.') }}
@endif
@endunless

@if($actionUrl)
{{ $actionLabel }}: {{ $actionUrl }}
@if($account)
Link válido até {{ $expiresAt }}. Não compartilhe este e-mail.
@endif
@endif

Barbearia 85 · A tradição do corte, a modernidade do estilo.
Mensagem automática. Não responder.
