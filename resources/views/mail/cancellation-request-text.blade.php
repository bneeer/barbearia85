BARBEARIA 85

{{ $title }}

Recebemos uma solicitação para consultar seus agendamentos. Nenhum horário foi cancelado.
Se não foi você, ignore esta mensagem.
Links válidos até {{ $expiresAt }}. Não compartilhe este e-mail.
A abertura do link apenas exibe uma confirmação.

@foreach($appointments as $appointment)
Serviços: {{ $appointment['service'] }}
Unidade: {{ $appointment['barbershop'] }}
Profissional: {{ $appointment['barber'] }}
Horário: {{ $appointment['starts_at'] }} ({{ $appointment['timezone'] }})
Prazo para cancelar: {{ $appointment['deadline'] }} ({{ $appointment['timezone'] }})
@if($appointment['reason'])
{{ $appointment['reason'] }}
@elseif(isset($links[$appointment['id']]))
Cancelar este agendamento: {{ $links[$appointment['id']] }}
@endif
@if($appointment['phones'])
Contato da unidade: {{ implode(' · ', $appointment['phones']) }}
@endif

@endforeach
@if(isset($links['all']))
Cancelar todos os agendamentos: {{ $links['all'] }}
O cancelamento de todos só será realizado se todos os agendamentos listados ainda forem elegíveis na confirmação.
@endif

O prazo de cada unidade será verificado novamente ao confirmar o cancelamento.
Mensagem automática. Não responder.
