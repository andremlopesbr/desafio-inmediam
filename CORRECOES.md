# CORRECOES.md — Desafio InMediam

Registro completo dos problemas identificados no projeto legado, correções implementadas e decisões técnicas tomadas durante a manutenção.

---

## 1. Bugs e Lógica Financeira

### FINDING-001 — Valor da cobrança confiado ao frontend

**Classificação:** BUG / SECURITY

**Problema:** O `BillingController` original utilizava `$request->amount` diretamente para criar a cobrança no Asaas e registrar o pagamento local. O frontend enviava o campo `amount` no payload, que podia ser manipulado via DevTools ou interceptação da requisição.

**Impacto:** Risco de integridade financeira. Um usuário poderia pagar R$ 0,01 por uma cobrança de R$ 79,90, e o sistema registraria o valor manipulado como legítimo tanto no gateway quanto no banco de dados local.

**Solução:** Criado `PayBillingRequest` que não aceita `amount` como campo de entrada. O valor financeiro é extraído exclusivamente de `$billing->amount` no banco de dados, dentro do `BillingPaymentService`.

**Justificativa:** O valor autorizado de uma cobrança deve vir sempre do registro confiável no backend, nunca do cliente HTTP.

**Arquivos principais:** `PayBillingRequest.php`, `BillingPaymentService.php`, `BillingController.php`
**Task:** KAN-04 · **Commit:** `aeb2986`
**Status:** Concluído

---

### FINDING-002 — Billing marcado como pago sem confirmar status do gateway

**Classificação:** BUG

**Problema:** O código original executava `$billing->status = 'paid'` imediatamente após receber qualquer resposta do Asaas, sem verificar se o status retornado pelo gateway indicava confirmação real. O Asaas pode retornar status como `PENDING`, `RECEIVED`, `CONFIRMED` ou outros. Marcar como `paid` sem verificar `CONFIRMED` gera divergência entre o estado local e o estado real no gateway.

**Impacto:** Cobranças poderiam ser marcadas como pagas localmente mesmo quando o pagamento foi recusado, está pendente de análise ou falhou na operadora.

**Solução:** O `BillingPaymentService` agora interpreta o campo `status` retornado pelo `AsaasService::payWithCreditCard()`. O billing só é marcado como `paid` quando `status === 'CONFIRMED'`. O `paid_at` do Payment é preenchido apenas nesse caso.

**Justificativa:** O status do pagamento deve refletir a confirmação real do gateway, não apenas o sucesso da chamada HTTP.

**Arquivos principais:** `BillingPaymentService.php`, `AsaasService.php`
**Tasks:** KAN-06 / KAN-07 · **Commits:** `4cc3dee`, `702fd0d`
**Status:** Concluído

---

### FINDING-003 — Ausência de proteção contra pagamento duplicado

**Classificação:** BUG / RESILIENCE

**Problema:** O fluxo original não possuía nenhum controle de concorrência. Duas requisições simultâneas de pagamento para a mesma cobrança poderiam ambas passar pela verificação de status, gerando cobranças duplicadas no Asaas e registros duplicados no banco local.

**Impacto:** Risco financeiro direto — o cliente poderia ser cobrado múltiplas vezes pela mesma assinatura.

**Solução:** Introduzido `DB::transaction()` com `lockForUpdate()` no `BillingPaymentService`. Dentro da transação, o Billing é recarregado com lock pessimista e seu status é revalidado antes de prosseguir. Se o status não for `pending`, uma `DomainException` é lançada (tratada no Controller como HTTP 409 Conflict).

**Justificativa:** Lock pessimista é a proteção mínima adequada sem introduzir complexidade de idempotency keys ou webhooks. Evoluções possíveis ficam registradas na seção de Limitações.

**Arquivos principais:** `BillingPaymentService.php`, `BillingController.php`
**Task:** KAN-07 · **Commit:** `702fd0d`
**Status:** Concluído

---

### FINDING-004 — Criação repetida de customer no Asaas

**Classificação:** BUG

**Problema:** O código original executava `POST /customers` a cada pagamento, sem verificar se o customer já existia no Asaas. Isso gerava cadastros duplicados no gateway para o mesmo CPF/CNPJ.

**Impacto:** Poluição da base de customers no Asaas. Potenciais inconsistências em relatórios e cobranças futuras vinculadas a IDs diferentes do mesmo cliente.

**Solução:** Criado `AsaasService::findOrCreateCustomer()` que primeiro consulta `GET /customers?cpfCnpj=...`. Se o customer existe, retorna o ID existente. Apenas se a resposta HTTP for 2xx e a lista estiver vazia, executa o `POST /customers`. Erros de busca (4xx, 5xx, timeout) lançam exceção e impedem a criação.

**Justificativa:** Padrão find-or-create é a abordagem mais simples e adequada para evitar duplicações sem necessidade de cache ou sincronização complexa.

**Arquivos principais:** `AsaasService.php`
**Task:** KAN-05 · **Commit:** `6707dcb`
**Status:** Concluído

---

### FINDING-005 — Consulta duplicada de Billing no Controller

**Classificação:** BUG / ARCHITECTURE

**Problema:** O `BillingController::pay()` original executava `Billing::find($id)` e, na linha seguinte, `Billing::where('id', $id)->first()` novamente para verificar existência — duas queries para o mesmo propósito, com o resultado da primeira sendo ignorado na verificação.

**Impacto:** Redundância lógica e potencial de bugs sutis (a variável `$billing` da primeira consulta era usada no fluxo, mas a verificação de existência consultava separadamente).

**Solução:** Migrado para Route Model Binding do Laravel. O `Billing` é injetado diretamente na assinatura do método, e o framework retorna automaticamente 404 se o ID não existir.

**Justificativa:** Route Model Binding é o mecanismo idiomático do Laravel para resolver modelos a partir de parâmetros de rota, eliminando queries manuais duplicadas.

**Arquivos principais:** `BillingController.php`, `routes/api.php`
**Tasks:** KAN-05 / KAN-06 · **Commits:** `6707dcb`, `4cc3dee`
**Status:** Concluído

---

## 2. Segurança

### FINDING-006 — Credenciais e configuração do Asaas hardcoded no Controller

**Classificação:** SECURITY / CONFIGURATION

**Problema:** A API Key do Asaas (`$aact_YourSandboxKeyHere`) e a Base URL estavam definidas diretamente como variáveis locais dentro do `BillingController::pay()`. A URL original (`https://sandbox.asaas.com/api/v3`) também estava incorreta em relação ao endpoint atual do Sandbox.

**Impacto:** Exposição de credenciais em versionamento. Acoplamento da configuração ao código-fonte. Impossibilidade de alternar entre ambientes (sandbox/produção) sem modificar o Controller.

**Solução:** Credenciais externalizadas para `.env` → `config/services.php` → `AsaasService`. O `.env.example` foi criado com os campos `ASAAS_API_KEY` e `ASAAS_BASE_URL`. O Controller não possui mais qualquer referência a API keys ou URLs do gateway.

**Justificativa:** Princípio de separação de configuração (12-Factor App). O fluxo `env → config → service` é o padrão Laravel.

**Arquivos principais:** `backend/.env.example`, `config/services.php`, `AsaasService.php`
**Task:** KAN-03 · **Commit:** `9751d48`
**Status:** Concluído

---

### FINDING-007 — Models com `$guarded = []` (mass assignment irrestrito)

**Classificação:** SECURITY

**Problema:** Os models `Billing`, `Payment` e `CreditCard` no código original declaravam `protected $guarded = []`, permitindo mass assignment de qualquer campo, incluindo `id`, `created_at`, `status` e relacionamentos.

**Impacto:** Em cenários onde dados não sanitizados alcançam `Model::create()` ou `Model::update()`, campos críticos poderiam ser sobrescritos (ex: `status`, `amount_paid`, `billing_id`).

**Solução:** Substituído `$guarded = []` por `$fillable` explícito em cada model, listando exclusivamente os campos que o código precisa popular via mass assignment.

**Justificativa:** `$fillable` explícito é a prática recomendada pelo Laravel para controle de mass assignment, especialmente em operações financeiras.

**Arquivos principais:** `Billing.php`, `Payment.php`, `CreditCard.php`
**Tasks:** KAN-06 / KAN-08 · **Commits:** `4cc3dee`, `0b5e23e`
**Status:** Concluído

---

### FINDING-008 — Exposição de `card_token` na API

**Classificação:** SECURITY

**Problema:** O model `CreditCard` original não possuía `$hidden`, fazendo com que o `card_token` (token de cobrança recorrente gerado pelo Asaas) fosse serializado em respostas JSON como `GET /api/billing/{id}` (via eager loading `payments.creditCard`).

**Impacto:** O token de cartão permite realizar cobranças sem os dados completos do cartão. Sua exposição em endpoints de leitura representa risco de uso indevido.

**Solução:** Adicionado `protected $hidden = ['card_token']` ao model `CreditCard`. O token permanece persistido (necessário para cobranças recorrentes futuras), mas não é serializado em respostas JSON.

**Justificativa:** `$hidden` é o mecanismo nativo do Eloquent para impedir serialização de campos sensíveis sem alterar schema ou lógica de persistência.

**Arquivos principais:** `CreditCard.php`
**Task:** KAN-08 · **Commit:** `0b5e23e`
**Status:** Concluído

---

### FINDING-009 — Normalização insegura de `card_last_four`

**Classificação:** SECURITY

**Problema:** O código original persistia `$response->creditCard['creditCardNumber']` diretamente no campo `card_last_four`. Embora o Asaas tipicamente retorne apenas os últimos 4 dígitos mascarados, a aplicação não normalizava esse valor, confiando cegamente no formato da resposta externa.

**Impacto:** Se o Asaas alterasse o formato de retorno (ex: devolvendo mais dígitos ou formato diferente), dados além dos 4 últimos dígitos poderiam ser persistidos.

**Solução:** Aplicado `substr($data['creditCard']['creditCardNumber'], -4)` no `AsaasService::payWithCreditCard()`, garantindo que apenas os 4 últimos caracteres sejam extraídos, independentemente do formato de entrada.

**Justificativa:** Normalização defensiva — não confiar no formato da resposta externa quando o requisito é armazenar exatamente 4 dígitos.

**Arquivos principais:** `AsaasService.php`
**Task:** KAN-08 · **Commit:** `0b5e23e`
**Status:** Concluído

---

### FINDING-010 — Dados de entrada do cartão sem validação no backend

**Classificação:** SECURITY / VALIDATION

**Problema:** O `BillingController::pay()` original recebia os dados do cartão via `Request` genérico sem nenhuma validação. Campos como `card_number`, `expiry_date` e `cvv` eram passados diretamente ao Asaas sem verificar formato, comprimento ou presença.

**Impacto:** Dados inválidos ou malformados seriam enviados ao gateway, gerando erros desnecessários. Ausência de feedback estruturado ao usuário sobre campos incorretos.

**Solução:** Criado `PayBillingRequest` com regras de validação: `card_number` (13-19 dígitos, com sanitização de espaços/hifens), `expiry_date` (formato MM/AA, mês válido, não expirado), `cvv` (3-4 dígitos), `card_holder_name` (obrigatório). O campo `amount` foi explicitamente removido do request.

**Justificativa:** Validação no backend é obrigatória mesmo quando o frontend valida, pois o frontend pode ser contornado.

**Arquivos principais:** `PayBillingRequest.php`
**Task:** KAN-04 · **Commit:** `aeb2986`
**Status:** Concluído

---

## 3. Clean Code / Arquitetura

### FINDING-011 — BillingController com múltiplas responsabilidades

**Classificação:** ARCHITECTURE

**Problema:** O `BillingController::pay()` original continha ~60 linhas com: instanciação de HTTP client, headers de autenticação, 3 chamadas ao Asaas, parsing de respostas, persistência de 3 models e atualização de status. Responsabilidades de integração, regra de negócio e persistência estavam todas no Controller.

**Impacto:** Baixa testabilidade (impossível testar regras de pagamento sem fazer chamadas HTTP reais). Violação de SRP. Manutenção custosa — qualquer alteração no gateway exigia modificar o Controller.

**Solução implementada em duas etapas:**

1. **KAN-05:** Criado `AsaasService` para encapsular toda a integração com o gateway (configuração HTTP, endpoints, headers, parsing de respostas, tratamento de erros).
2. **KAN-06:** Criado `BillingPaymentService` para encapsular a regra de negócio de pagamento (validação de estado, orquestração de chamadas ao `AsaasService`, persistência). O `BillingController::pay()` ficou com ~8 linhas: recebe request, chama service, retorna response.

**Justificativa:** Separação em Controller → Service → Gateway segue o princípio de responsabilidade única e permite testes unitários com mocks das dependências externas.

**Arquivos principais:** `BillingController.php`, `AsaasService.php`, `BillingPaymentService.php`
**Tasks:** KAN-05 / KAN-06 · **Commits:** `6707dcb`, `4cc3dee`
**Status:** Concluído

---

## 4. Resiliência

### FINDING-012 — Resposta do gateway aceita sem validação

**Classificação:** RESILIENCE

**Problema:** O código original fazia `$response = (object) $response->json()` e acessava propriedades como `$customer->id`, `$charge->id`, `$response->creditCard` sem verificar se a chamada HTTP teve sucesso ou se os campos esperados existiam na resposta.

**Impacto:** Um erro 4xx/5xx do Asaas causaria exceções do tipo `Undefined property` ou `Trying to access array offset on null`, resultando em HTTP 500 genérico sem informação útil.

**Solução:** Criado método `AsaasService::handleResponse()` que: (a) verifica se `$response->failed()`; (b) diferencia erros do servidor (5xx → HTTP 502) e do cliente (4xx → HTTP 422); (c) extrai campos `code` e `description` da resposta estruturada de erros do Asaas; (d) registra log estruturado seguro (sem payload, sem PAN, sem token); (e) valida presença de campos obrigatórios na resposta de sucesso antes de retorná-los.

**Justificativa:** Toda integração externa deve tratar falhas explicitamente. O padrão de handler centralizado evita duplicação da lógica de tratamento em cada método.

**Arquivos principais:** `AsaasService.php`, `AsaasException.php`
**Task:** KAN-05 · **Commit:** `6707dcb`
**Status:** Concluído

---

### FINDING-013 — Ausência de timeout e tratamento de falha de conexão

**Classificação:** RESILIENCE

**Problema:** O código original usava `Http::withHeaders(...)` sem definir timeout, dependendo do timeout padrão do PHP/cURL. Não havia tratamento para `ConnectionException`, que ocorre em caso de timeout, DNS failure ou indisponibilidade.

**Impacto:** Uma indisponibilidade do Asaas poderia travar a requisição do usuário por tempo indeterminado. A exceção resultante seria um HTTP 500 genérico.

**Solução:** `AsaasService::client()` define `->timeout(15)`. Cada método público captura `ConnectionException` e lança `AsaasException` com HTTP 504 e mensagem descritiva. Não há retry automático para evitar duplicação de operações financeiras.

**Justificativa:** Timeout de 15s é razoável para APIs de pagamento. Ausência de retry em operações financeiras é intencional — sem idempotency key, um retry poderia gerar cobrança duplicada no gateway.

**Arquivos principais:** `AsaasService.php`
**Task:** KAN-05 · **Commit:** `6707dcb`
**Status:** Concluído

---

### FINDING-014 — Placeholders inválidos em `creditCardHolderInfo`

**Classificação:** RESILIENCE / BUG

**Problema:** O código original (e o código herdado no `AsaasService`) enviava placeholders fictícios inválidos nos campos obrigatórios do `creditCardHolderInfo`:

- `phone`: `'0000000000'` — formato inválido
- `postalCode`: `'00000000'` — CEP inexistente
- `addressNumber`: `'0'` — número inválido

**Impacto:** O Sandbox do Asaas rejeita a requisição com HTTP 400: *"O CEP informado é inválido"*. O fluxo de pagamento não pode ser concluído.

**Solução:** Pendente de implementação. A correção mínima é substituir os placeholders por valores estruturalmente válidos (ex: CEP `01310100`, telefone `11999999999`, número `1000`) que o Sandbox aceite. Opcionalmente, esses campos podem vir do cadastro do Customer no futuro.

**Justificativa:** O Asaas exige dados mínimos de endereço do titular do cartão para antifraude. Os valores não precisam ser reais no Sandbox, mas devem ser estruturalmente válidos.

**Arquivos principais:** `AsaasService.php`
**Task:** KAN-09 (smoke test) · **Status:** Pendente — aguardando aprovação para aplicar correção

---

### FINDING-015 — URL do Sandbox duplicada/incorreta no `.env.example`

**Classificação:** CONFIGURATION

**Problema:** O `.env.example` original do backend não possuía configuração do Asaas. Após a criação na KAN-03, a URL do Sandbox continha duplicação de chave (`ASAAS_BASE_URL=ASAAS_BASE_URL=https://...`), que foi corrigida na KAN-09.

**Impacto:** Desenvolvedores copiando o `.env.example` receberiam uma URL malformada, impedindo conexão com o Asaas.

**Solução:** Corrigido para `ASAAS_BASE_URL=https://api-sandbox.asaas.com/v3`.

**Arquivos principais:** `backend/.env.example`
**Task:** KAN-09 · **Status:** Concluído (working tree)

---

## 5. Frontend / UX

### FINDING-016 — URL da API hardcoded como `localhost:8000`

**Classificação:** UX / CONFIGURATION

**Problema:** Tanto `billing.tsx` quanto `payment-form.tsx` instanciavam `axios.create()` inline com URL `http://localhost:8000` hardcoded diretamente nos componentes.

**Impacto:** Impossibilidade de alterar o backend sem modificar código-fonte. Duplicação da configuração em múltiplos componentes. Acoplamento a ambiente de desenvolvimento específico.

**Solução:** Criado `frontend/src/lib/api.ts` com instância centralizada do Axios usando `import.meta.env.VITE_API_URL`. Criado `frontend/.env.example` com a variável documentada.

**Arquivos principais:** `frontend/src/lib/api.ts`, `frontend/.env.example`, `billing.tsx`, `payment-form.tsx`
**Task:** KAN-09 · **Status:** Concluído (working tree)

---

### FINDING-017 — `toast.success` exibido no `onError`

**Classificação:** BUG / UX

**Problema:** No `payment-form.tsx` original, o callback `onError` da mutation exibia `toast.success('Dados salvos com sucesso!')` — mensagem de sucesso em fluxo de erro, com texto que sequer correspondia à operação (pagamento, não salvamento de dados).

**Impacto:** Usuário recebia feedback de sucesso quando o pagamento falhava, sem qualquer indicação do erro real.

**Solução:** Substituído por `toast.error()` com mensagem de erro. O handler agora tenta extrair a mensagem segura do backend (`response.data.error` ou `response.data.message`) antes de usar fallback genérico.

**Arquivos principais:** `payment-form.tsx`
**Task:** KAN-09 · **Status:** Concluído (working tree)

---

### FINDING-018 — Validação Zod insuficiente no formulário

**Classificação:** VALIDATION / UX

**Problema:** O schema Zod original declarava todos os campos do cartão como `z.string()` simples, sem nenhuma validação de formato, comprimento ou regra de negócio (ex: cartão expirado).

**Impacto:** Dados claramente inválidos (ex: letras no número do cartão, validade `99/99`) passavam pela validação do frontend e eram enviados ao backend, gerando erros pouco informativos para o usuário.

**Solução:** Schema Zod reforçado com: regex para 13-19 dígitos no card number (com transform para remover espaços), regex e refine para validade MM/AA com validação de expiração, regex para CVV 3-4 dígitos, `min(1)` para holder name. Adicionadas máscaras de input com formatação automática (agrupamento de 4 dígitos no cartão, `/` automático na validade, limite de caracteres no CVV).

**Arquivos principais:** `payment-form.tsx`
**Tasks:** KAN-09 · **Status:** Concluído (working tree)

---

### FINDING-019 — Estado duplicado entre `useState` e React Hook Form

**Classificação:** ARCHITECTURE / UX

**Problema:** O componente original mantinha `useState<string>()` para `cardNumber` e `cvv` em paralelo com o `register` do React Hook Form, com `onChange` manual sincronizando ambos. Isso gerava conflito na fonte de verdade e complexidade desnecessária.

**Impacto:** Possíveis dessincronizações entre o estado visual (card preview) e o estado do formulário. Código mais complexo de manter.

**Solução:** Removidos os `useState` duplicados. O card preview agora usa `watch()` do RHF como única fonte. As máscaras de input usam `setValue()` dentro do `onChange` do `register()`, mantendo o RHF como single source of truth.

**Arquivos principais:** `payment-form.tsx`
**Task:** KAN-09 · **Status:** Concluído (working tree)

---

### FINDING-020 — Ausência de estados de loading, error e 404

**Classificação:** UX

**Problema:** O componente `Billing` renderizava diretamente `data?.plan.name` sem tratar os estados de carregamento (`isLoading`), erro (`isError`) e cobrança inexistente (resposta com `error`). O usuário via uma página em branco durante o carregamento ou para IDs inválidos.

**Impacto:** Experiência de usuário degradada. Sem feedback visual em estados críticos (loading, erro de rede, 404).

**Solução:** Adicionados tratamentos para `isLoading` (spinner), `isError` (mensagem de erro com botão de retry), e verificação do campo `error` na resposta (tela de 404 amigável). Datas de vencimento e pagamento formatadas em pt-BR.

**Arquivos principais:** `billing.tsx`
**Task:** KAN-09 · **Status:** Concluído (working tree)

---

### FINDING-021 — Ausência de invalidação de cache após pagamento

**Classificação:** UX / BUG

**Problema:** Após pagamento bem-sucedido, o frontend exibia `toast.success` mas não invalidava a query do React Query. O usuário precisava recarregar a página manualmente para ver o status atualizado (de "Pendente" para "Pago") e o comprovante.

**Impacto:** O estado visual da cobrança ficava inconsistente com o estado real após o pagamento.

**Solução:** Adicionado `queryClient.invalidateQueries({ queryKey: ['billing', billingId] })` no `onSuccess` da mutation. Isso força o refetch automático dos dados da cobrança, atualizando a interface em tempo real.

**Arquivos principais:** `payment-form.tsx`, `home.tsx`, `BillingController.php`
**Task:** KAN-09 · **Status:** Concluído (working tree)

**Complemento — Home desatualizada após pagamento:**
Durante o smoke final foi identificado que a Home mantinha plano, cliente e status hardcoded, por isso não refletia o estado real da cobrança após o pagamento.

**Correção:** a Home passou a consultar os billings pela API usando React Query e as mesmas query keys do fluxo de Billing. O endpoint `show()` também passou a carregar a relação `customer`.

**Motivo:** manter o backend como fonte de verdade e garantir consistência visual entre Home e tela de cobrança.

---

### FINDING-022 — Classe CSS inválida `max-` no home.tsx

**Classificação:** BUG / UX

**Problema:** O componente `Home` usava a classe Tailwind `max-` (truncada/incompleta) no container principal. Classe CSS sem valor não produz nenhum efeito de limitação de largura.

**Impacto:** Layout não limitado horizontalmente conforme o design provavelmente pretendia.

**Solução:** Substituído `max-` por `max-w-2xl`.

**Arquivos principais:** `home.tsx`
**Task:** KAN-09 · **Status:** Concluído (working tree)

---

### FINDING-023 — Frontend enviava `amount` no payload de pagamento

**Classificação:** BUG / SECURITY

**Problema:** O `payment-form.tsx` original incluía `amount` (vindo via props do componente) no body do POST enviado ao backend. Embora o risco real estivesse no backend aceitar esse valor (FINDING-001), o frontend não deveria enviar informação financeira que compete ao backend determinar.

**Impacto:** Superfície de ataque desnecessária — mesmo com validação no backend, o campo `amount` no payload poderia ser confuso para futuros mantenedores.

**Solução:** Campo `amount` removido do payload da mutation. O POST envia apenas os 4 campos do cartão. A prop `amount` foi mantida apenas para exibição visual (se necessário).

**Arquivos principais:** `payment-form.tsx`
**Task:** KAN-09 · **Status:** Concluído (working tree)

---

## 6. Infraestrutura

### FINDING-024 — Docker PostgreSQL sem versão fixada

**Classificação:** CONFIGURATION

**Problema:** O `docker-compose.yml` original usava `image: postgres` (latest) e a porta 5432 não era configurável.

**Impacto:** Quebras potenciais ao receber major updates do PostgreSQL. Conflito de porta para desenvolvedores com PostgreSQL local na 5432.

**Solução:** Fixado `image: postgres:16`. Porta externa configurável via `${POSTGRES_PORT:-5432}`.

**Arquivos principais:** `docker-compose.yml`
**Task:** KAN-02 · **Commit:** `c3d6066`
**Status:** Concluído

---

## 7. Decisões Técnicas Relevantes

### DEC-001 — Não usar retry automático em operações financeiras

O `AsaasService` captura `ConnectionException` (timeout) mas **não** faz retry. Em uma operação financeira, um retry após timeout poderia gerar cobrança duplicada no gateway, pois não há como saber se a requisição original foi processada. A abordagem segura é falhar e informar o usuário, delegando eventual reprocessamento a uma ação explícita.

**Evolução possível:** Implementar idempotency keys (gerar um UUID único por tentativa de pagamento e enviá-lo ao Asaas para garantir processamento único).

---

### DEC-002 — Lock pessimista vs. idempotency key

Optou-se por `lockForUpdate()` dentro de `DB::transaction()` como mecanismo de proteção contra concorrência. Esta é uma solução de nível de banco de dados, eficaz para requisições simultâneas na mesma instância. Não protege contra cenários distribuídos (múltiplas instâncias da aplicação).

**Evolução possível:** Combinar lock pessimista com idempotency key no gateway para proteção end-to-end.

---

### DEC-003 — `card_token` persistido mas oculto

O `card_token` retornado pelo Asaas é necessário para cobranças recorrentes futuras. A decisão foi mantê-lo persistido no banco (não podemos descartá-lo sem entender o roadmap), mas tratá-lo como dado sensível via `$hidden` no Eloquent, impedindo serialização acidental em respostas JSON.

---

### DEC-004 — DomainException para regra de negócio vs. AsaasException para gateway

Para distinguir claramente erros de domínio (cobrança já paga, status inválido) de erros de integração (falha no Asaas), foram usadas exceções diferentes: `DomainException` para regras internas (HTTP 409 Conflict) e `AsaasException` para falhas do gateway (HTTP 422 ou 502). Isso evita que uma regra de domínio seja tratada como falha externa.

---

### DEC-005 — Logging estruturado e seguro do gateway

O `AsaasService::handleResponse()` registra logs de erro do Asaas contendo apenas: operação, HTTP status, error code e description. **Nunca registra:** request body, response body completo, PAN, CVV, token de cartão, API key ou headers de autenticação.

---

## 8. Evidências e Testes

### Testes automatizados criados

| Arquivo | Cenários | Observação |
|---|---|---|
| `AsaasServiceTest.php` | findOrCreateCustomer (sucesso, criação, 4xx, 5xx, timeout); createCreditCardCharge; payWithCreditCard | Usa `Http::fake()` |
| `PayBillingValidationTest.php` | Campos obrigatórios, formatos, cartão expirado, campo `amount` rejeitado | Validação via FormRequest |
| `BillingPaymentServiceTest.php` | Fluxo completo, billing não-pending, billing já pago, lock + status check | Usa mock do AsaasService |
| `BillingControllerSecurityTest.php` | Ausência de dados sensíveis na resposta do `GET /billing/{id}` | Verifica `$hidden` |

### Limitação dos testes

Os testes que dependem de transações de banco (`lockForUpdate`, `DB::transaction`) não executam no ambiente local quando o driver é `sqlite`, pois o SQLite não suporta `FOR UPDATE`. Esses testes requerem PostgreSQL para execução completa. A lógica transacional foi verificada por inspeção de código e smoke test manual.

### Validação de frontend

- `npm run lint`: 0 errors, 0 warnings
- `npm run build`: sucesso (tsc + vite build)

---

## 9. Limitações e Evoluções

### Limitações atuais

1. **Sem idempotency key:** O lock pessimista protege contra concorrência na mesma instância, mas não garante idempotência end-to-end com o gateway.

2. **Sem webhook de reconciliação:** O status do pagamento é determinado pela resposta síncrona do Asaas. Se o gateway confirmar após a resposta (ex: análise antifraude), o status local não será atualizado.

3. **Placeholders de endereço:** Os campos `phone`, `postalCode` e `addressNumber` do `creditCardHolderInfo` usam placeholders. No Sandbox atual do Asaas, placeholders inválidos causam rejeição (FINDING-014). A correção mínima está pendente de aprovação.

4. **Customer sem endereço real:** O cadastro do customer no Asaas não inclui dados de endereço do model local. Se futuramente necessário para antifraude ou nota fiscal, o schema do `Customer` precisaria ser estendido.

5. **Testes transacionais:** Dependem de PostgreSQL. Não executam com SQLite.

### Evoluções possíveis (fora do escopo)

- Idempotency keys por tentativa de pagamento
- Webhook Asaas para reconciliação assíncrona de status
- Cache de customer ID do Asaas no model Customer local
- Dados reais de endereço do titular via cadastro
- Observabilidade estruturada (correlation IDs, métricas de gateway)

---

## 10. Premissas do Ambiente de Homologação

### Cadastro comercial da conta Sandbox

A conta Asaas Sandbox autenticada pela API key precisa ter informações comerciais completas (CPF/CNPJ do titular da conta preenchido). Sem esse cadastro, o Asaas recusa a criação de cobranças de cartão de crédito com `invalid_object`. Esse é um requisito de configuração do ambiente, não um bug do código.

**Diagnóstico realizado:** `GET /myAccount/commercialInfo` retornou `personType: null` e `cpfCnpj: null`. Status `commercialInfo: PENDING`.

**Ação necessária:** Completar o cadastro comercial no painel Asaas Sandbox ou via API.

### Variável de ambiente do frontend

O frontend requer a variável `VITE_API_URL` configurada em um arquivo `frontend/.env` local (não versionado). O arquivo `frontend/.env.example` foi criado como referência.

---

## 11. Resumo

| # | Finding | Classificação | Status | Task | Commit |
|---|---|---|---|---|---|
| 001 | Valor financeiro confiado ao frontend | BUG/SECURITY | Concluído | KAN-04 | `aeb2986` |
| 002 | Billing marcado paid sem CONFIRMED | BUG | Concluído | KAN-06/07 | `4cc3dee`/`702fd0d` |
| 003 | Sem proteção contra pagamento duplicado | BUG/RESILIENCE | Concluído | KAN-07 | `702fd0d` |
| 004 | Criação repetida de customer no Asaas | BUG | Concluído | KAN-05 | `6707dcb` |
| 005 | Consulta duplicada de Billing | BUG/ARCH | Concluído | KAN-05/06 | `6707dcb`/`4cc3dee` |
| 006 | Credenciais Asaas hardcoded | SECURITY/CONFIG | Concluído | KAN-03 | `9751d48` |
| 007 | Models com `$guarded = []` | SECURITY | Concluído | KAN-06/08 | `4cc3dee`/`0b5e23e` |
| 008 | Exposição de `card_token` na API | SECURITY | Concluído | KAN-08 | `0b5e23e` |
| 009 | Normalização insegura de card_last_four | SECURITY | Concluído | KAN-08 | `0b5e23e` |
| 010 | Entrada do cartão sem validação backend | SECURITY/VALID | Concluído | KAN-04 | `aeb2986` |
| 011 | Controller com múltiplas responsabilidades | ARCH | Concluído | KAN-05/06 | `6707dcb`/`4cc3dee` |
| 012 | Resposta do gateway aceita sem validação | RESILIENCE | Concluído | KAN-05 | `6707dcb` |
| 013 | Sem timeout nem tratamento de conexão | RESILIENCE | Concluído | KAN-05 | `6707dcb` |
| 014 | Placeholders inválidos no holderInfo | RESILIENCE/BUG | Pendente | KAN-09 | — |
| 015 | URL do Sandbox duplicada no .env.example | CONFIG | Concluído | KAN-09 | working tree |
| 016 | URL da API hardcoded no frontend | UX/CONFIG | Concluído | KAN-09 | working tree |
| 017 | `toast.success` no `onError` | BUG/UX | Concluído | KAN-09 | working tree |
| 018 | Validação Zod insuficiente | VALID/UX | Concluído | KAN-09 | working tree |
| 019 | Estado duplicado useState/RHF | ARCH/UX | Concluído | KAN-09 | working tree |
| 020 | Sem loading/error/404 na página billing | UX | Concluído | KAN-09 | working tree |
| 021 | Sem invalidação de cache após pagamento | UX/BUG | Concluído | KAN-09 | working tree |
| 022 | Classe CSS `max-` truncada | BUG/UX | Concluído | KAN-09 | working tree |
| 023 | Frontend enviava `amount` na requisição | BUG/SECURITY | Concluído | KAN-09 | working tree |
| 024 | Docker PostgreSQL sem versão fixada | CONFIG | Concluído | KAN-02 | `c3d6066` |