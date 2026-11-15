# Operação financeira

O módulo **Financeiro** (`/finance`) é acessível a administradores e barbeiros. Administradores consultam todas as unidades; barbeiros veem somente suas próprias comissões. Salários-base, despesas, custos e lucro não são enviados ao perfil de barbeiro.

## Implantação e recuperação da migration

Execute `php artisan migrate` após atualizar o código. No MySQL, uma falha de migration pode deixar alterações de esquema aplicadas mesmo sem registrar a migration como concluída. A migration financeira reconhece as colunas e tabelas compatíveis já criadas e recupera o índice de vigência salarial caso esteja ausente.

Se uma tabela existente estiver incompleta ou incompatível, a execução é interrompida com uma mensagem para revisão do esquema. Preserve os dados, faça backup e investigue o estado antes de corrigir; não marque a migration como executada manualmente nem use `migrate:fresh` em um banco com dados.

## Despesas e contas a pagar

Cadastre cada despesa com unidade, categoria, valor, data de competência e vencimento. O relatório de resultado considera o valor integral na competência, mesmo quando ainda não foi pago. Registre pagamentos em uma ou mais parcelas; o saldo pendente é calculado a partir do histórico de pagamentos.

Uma despesa recorrente cria automaticamente uma ocorrência mensal. A geração é idempotente e roda diariamente pelo scheduler (`finance:generate-recurring-expenses`), criando no máximo uma despesa por recorrência e mês. O vencimento é limitado ao último dia do mês quando o dia configurado não existir. Pausar a recorrência impede novas ocorrências; despesas já geradas permanecem no contas a pagar e não são alteradas.

Ao registrar um pagamento, escolha se ele saiu do caixa aberto da unidade. Se associado ao caixa, informe a forma de pagamento e o saldo esperado é reduzido; não é possível pagar pelo caixa acima do saldo disponível daquela forma. Pagamentos fora da sessão do caixa continuam no histórico financeiro, mas não afetam o fechamento do caixa.

## Custos e checkout

Ao registrar uma entrada de estoque, informe a quantidade e o custo total da compra. O sistema atualiza o custo médio ponderado e registra o custo na movimentação. Saldos que já existiam sem custo conhecido não são considerados gratuitos: seu custo permanece desconhecido até o saldo chegar a zero e uma nova entrada estabelecer uma base conhecida. Saídas e vendas capturam o custo médio aplicável no momento; alterações posteriores no preço ou estoque não atualizam os snapshots dos checkouts anteriores.

Configure no serviço a comissão percentual e, opcionalmente, a quantidade de cada insumo usado por atendimento. No checkout, o desconto geral é rateado proporcionalmente ao valor bruto entre serviços e produtos; a comissão incide sobre o valor líquido do serviço. O salário-base mensal é configurado por vínculo entre barbeiro e unidade e é somado às comissões no cálculo do resultado. Em períodos parciais, o salário-base é rateado pelos dias do período em cada mês.

O checkout apresenta as quantidades de insumo sugeridas pela composição. A equipe confirma cada consumo para autorizar a baixa; se o estoque for insuficiente, o atendimento ainda é finalizado, a baixa não ocorre e um aviso é apresentado. O custo estimado da composição continua registrado como custo do serviço, mesmo que a baixa não tenha sido confirmada.

## Relatórios e limitações

O resultado estimado por competência considera checkouts registrados, despesas por competência, custos de produtos e insumos, comissões e salário-base. O fluxo de pagamentos usa a data das quitações de despesas e os checkouts registrados no período. Checkouts apenas registram a forma de pagamento informada pela equipe: não processam nem comprovam recebimento bancário.

Registros anteriores que não tenham snapshots de custos ou comissão são indicados como incompletos; o lucro estimado fica indisponível para o período quando esses dados faltam. Custos unitários desconhecidos não são tratados como zero. Os relatórios são ferramentas operacionais, não substituem escrituração fiscal/contábil.
