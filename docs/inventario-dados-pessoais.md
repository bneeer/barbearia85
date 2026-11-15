# Inventário interno de dados pessoais

Este inventário é um modelo de trabalho, não uma conclusão jurídica nem uma descrição confirmada do ambiente de produção. Os responsáveis devem revisar os tratamentos efetivos, preencher as pendências e validar as bases legais com assessoria apropriada. A existência de uma tabela ou configuração não comprova seu uso em produção. Não inserir dados pessoais reais ou credenciais neste documento.

Para cada tratamento, confirmar controlador e operadores, titulares, necessidade e minimização, base legal e sua fundamentação, fornecedores, acesso, local de armazenamento e eventual transferência internacional, prazo de retenção e forma de eliminação, inclusive em cópias e sistemas externos. As bases sugeridas abaixo são hipóteses para avaliação, não determinação de enquadramento.

## Categorias

### Cadastro e contato de clientes

- **Dados e finalidade:** nome, telefone e e-mail para identificar o cliente, agendar e comunicar sobre o atendimento; o cadastro também prevê observações. As reservas públicas e administrativas solicitam os três dados de contato.
- **Acesso:** administradores e profissionais autorizados conforme função e atendimento. Confirmar acessos operacionais e dos fornecedores.
- **Base legal a avaliar:** execução de contrato ou procedimentos preliminares solicitados pelo titular; avaliar outra hipótese para cada finalidade adicional e para observações.
- **Retenção:** definir prazo após o último atendimento e exceções.
- **Compartilhamento e local:** hospedagem/banco e, quando habilitado, provedor de e-mail; identificar fornecedores, regiões e eventuais transferências. O banco é configurável; SQLite é o padrão de exemplo, não uma confirmação de produção.
- **Exclusão:** definir procedimento e responsáveis, abrangendo registros associados, exportações e fornecedores. A tabela de clientes prevê exclusão lógica; isso não equivale à eliminação física.

### Agendamentos e cancelamentos

- **Dados e finalidade:** cliente, unidade, profissional, serviços, horários, situação e possíveis observações para prestação do serviço, gestão da agenda e esclarecimento de dúvidas. Solicitações de cancelamento incluem e-mail, dados das reservas e token para confirmação.
- **Acesso:** administradores e profissionais autorizados aos próprios atendimentos; confirmar acesso operacional e dos fornecedores. O cliente pode receber dados das suas reservas por e-mail quando o canal estiver habilitado.
- **Base legal a avaliar:** execução de contrato ou procedimentos preliminares; outras hipóteses para histórico, defesa de direitos e notificações devem ser avaliadas conforme finalidade.
- **Retenção:** definir prazo para histórico, exceções e solicitações de cancelamento. O link de cancelamento expira em uma hora; expiração do link não implica eliminação automática do registro.
- **Compartilhamento e local:** banco/hospedagem e provedor de e-mail quando habilitado; identificar fornecedores, regiões e transferências. Se o cliente optar por calendário externo, avaliar compartilhamento com esse serviço.
- **Exclusão:** definir procedimento para registros, solicitações, mensagens, exportações e cópias. Agendamentos admitem exclusão lógica; fechamentos possuem restrição de integridade que pode impedir exclusão física direta. Cancelar uma reserva altera sua situação, não equivale a apagar seus dados.

### Fechamentos e dados financeiros

- **Dados e finalidade:** serviços e produtos, valores, descontos, forma de pagamento, observações e vínculo com o atendimento para gestão e obrigações aplicáveis. A gestão financeira também registra despesas, vencimentos, pagamentos parciais, custos médios de estoque, composições de insumos, comissão por serviço e salário-base mensal por vínculo profissional-unidade. Há também esquema para pagamentos; confirmar uso efetivo. O fechamento não processa nem confirma recebimento.
- **Acesso:** administradores autorizados veem despesas, custos, salários e lucro estimado. Cada barbeiro pode consultar somente as próprias comissões; não recebe despesas, salário-base, custos ou lucro da unidade. Confirmar acesso da contabilidade, operação e fornecedores.
- **Base legal a avaliar:** execução de contrato, obrigação legal ou regulatória e eventual exercício regular de direitos, conforme cada finalidade e documento.
- **Retenção:** definir prazos e exceções com apoio contábil e jurídico.
- **Compartilhamento e local:** identificar hospedagem, banco, contabilidade, fornecedores e regiões; verificar exportações XLSX e seus destinatários.
- **Exclusão:** definir procedimentos para banco, exportações e cópias após avaliação das obrigações de retenção; o fechamento tem chaves estrangeiras com restrição de exclusão.

Os relatórios operacionais distinguem competência de quitação e podem estar incompletos para registros antigos sem snapshots de custo/comissão. Não são escrituração fiscal nem confirmação de processamento bancário.

### Programa de fidelidade

- **Dados e finalidade:** identificador HMAC derivado do e-mail do cliente, saldo e movimentações de carimbos, unidade, atendimento/fechamento associado e descrição da recompensa resgatada, para administrar os benefícios por unidade. O identificador derivado é pseudonimizado, não anônimo.
- **Acesso:** administradores da barbearia e clientes com acesso válido ao portal; confirmar acessos operacionais e dos fornecedores.
- **Base legal a avaliar:** execução de contrato ou outra hipótese adequada à participação no programa, a ser validada pelos responsáveis.
- **Retenção:** definir prazo para saldos e histórico de movimentações, inclusive após desativação do programa ou encerramento da relação com o cliente.
- **Compartilhamento e local:** os dados são armazenados no banco da aplicação; identificar hospedagem, banco, operadores, regiões e eventuais transferências.
- **Exclusão:** não há expurgo automático do identificador HMAC ou das movimentações. Definir tratamento em pedidos de privacidade, exclusão de clientes, fechamento de unidades, exportações e backups.

### Notificações, logs e filas

- **Dados e finalidade:** destinatário, situação, tentativas, códigos de falha e conteúdo de notificações para comunicação e acompanhamento de envios; logs e filas podem conter dados técnicos ou relacionados a falhas. Conteúdo e segredos específicos da outbox são criptografados, mas isso não significa que todos os seus campos ou logs sejam criptografados.
- **Consulta do cliente:** concessões de acesso armazenam e-mail criptografado, identificador derivado do e-mail, hash de token, expiração e vínculo com a notificação. O link dá acesso a todos os cadastros ativos associados ao endereço por até uma hora. Configurar servidor e observabilidade para não registrar URLs com o token; definir retenção e exclusão desses registros e da outbox.
- **Acesso:** administradores responsáveis pela operação e pessoas autorizadas com acesso à infraestrutura; identificar acesso de fornecedores.
- **Base legal a avaliar:** execução de contrato para comunicações pertinentes e outras hipóteses aplicáveis à operação e segurança, conforme finalidade.
- **Retenção:** definir prazo específico para registros e conteúdo, preferencialmente menor para conteúdo sensível; definir prazos para logs e filas. Não presumir expurgo automático.
- **Compartilhamento e local:** identificar hospedagem, provedor SMTP e eventuais serviços de logs, suas regiões e transferências; envio transacional é configurável e começa desativado na configuração de exemplo.
- **Exclusão:** definir rotinas para outbox, tentativas, filas, logs, provedor e backups, incluindo mensagens já entregues, que não podem ser recolhidas pela aplicação.

### Contas de equipe, autenticação e sessões

- **Dados e finalidade:** nome, e-mail, telefone, perfil, senha protegida, dados de autenticação em duas etapas, foto, tokens, IP, agente do navegador e sessão, conforme configuração; utilizados para acesso e segurança. O cadastro público de contas está desabilitado na configuração atual.
- **Acesso:** titular da conta e administradores ou responsáveis autorizados conforme o recurso; operadores de infraestrutura podem acessar armazenamento. Confirmar limites efetivos.
- **Base legal a avaliar:** execução de contrato, obrigação aplicável ou interesse legítimo, conforme relação, finalidade e avaliação jurídica.
- **Retenção:** definir para contas, sessões, tokens e fotos; tempo de sessão configurável não é política geral de retenção.
- **Compartilhamento e local:** identificar hospedagem, banco, armazenamento de arquivos e provedor de e-mail para recuperação e verificação de conta, além de regiões e transferências.
- **Exclusão:** confirmar fluxo de remoção de conta e arquivos associados, restrições de integridade, logs e cópias; definir tratamento de contas desativadas.

### Backups

- **Dados e finalidade:** cópias potencialmente abrangendo todas as categorias acima para recuperação de desastres, se realizadas.
- **Acesso:** responsáveis autorizados pela operação e fornecedores, a confirmar.
- **Base legal a avaliar:** hipótese adequada às finalidades de segurança e continuidade, conforme avaliação jurídica.
- **Retenção:** definir política de rotação e prazo; a existência, frequência e retenção de backups não estão confirmadas pelo código.
- **Compartilhamento e local:** identificar fornecedor, armazenamento, regiões, transferências e medidas de proteção.
- **Exclusão:** definir procedimento para expiração, exclusões solicitadas e dados restaurados, evitando reapresentação indevida após restauração.

### Protocolos de solicitações dos titulares

- **Dados e finalidade:** protocolo, data, canal, tipo, prazo, responsável, método geral de verificação, etapas, cadastros vinculados, resultado e justificativas mínimas para acompanhar solicitações. Os campos livres não devem conter documentos, cópias da resposta ou dados sensíveis.
- **Acesso:** administradores autorizados de todas as unidades e operadores da infraestrutura; confirmar demais acessos.
- **Base legal a avaliar:** obrigações aplicáveis ao atendimento de direitos e outras hipóteses para comprovação, conforme finalidade e validação jurídica.
- **Retenção:** prazo do próprio protocolo e histórico pendente de definição; a conclusão não elimina os registros.
- **Compartilhamento e local:** identificar hospedagem, banco, fornecedores e regiões; protocolo e resposta são comunicados manualmente pelo canal adotado.
- **Exclusão:** definir procedimento para protocolo, histórico, vínculos e cópias. A exclusão futura de um cadastro pode remover seu vínculo, mas o protocolo permanece; seus metadados também podem ser dados pessoais.

O [procedimento de solicitações](procedimento-solicitacoes-lgpd.md) descreve o atendimento operacional e os limites da prévia de exclusão.

## Pendências para os responsáveis

1. Identificar responsável pelo tratamento, contato específico para direitos dos titulares e encarregado, quando aplicável; confirmar quem recebe e responde solicitações.
2. Confirmar fornecedores contratados, localizações e transferência internacional; revisar e atualizar a política pública quando mudarem.
3. Definir e implementar, quando cabível, prazos, critérios de retenção, exclusão e rotação de backups; revisar cópias, exportações, logs e dependências.
4. Validar bases legais por finalidade e dados efetivamente usados, com apoio jurídico e contábil; evitar coletar observações sensíveis desnecessárias.
5. Revisar a política pública com os responsáveis antes de publicá-la como versão definitiva e mantê-la atualizada.
