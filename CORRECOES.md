# Descobertas e Correções

Registro incremental dos problemas identificados durante o desafio e das tasks responsáveis por tratá-los.

## Baseline — KAN-02

### FINDING-001 — Configuração do Asaas hardcoded

**Status:** Concluído
**Task:** KAN-03

A API Key e a URL do Asaas estão definidas diretamente no `BillingController`.
*Correção: API Key e Base URL foram externalizadas para configuração de ambiente.*

**Impacto:** exposição de credenciais e configuração acoplada ao código.

**Arquivo:** `backend/app/Http/Controllers/BillingController.php`

---

### FINDING-002 — Resposta do gateway não validada

**Status:** Concluído
**Task:** KAN-05

A resposta do Asaas é utilizada sem validação prévia de sucesso, podendo gerar erros ao acessar propriedades inexistentes.
*Correção: Extração da lógica para o AsaasService que agora valida HTTP e obriga a presença de chaves, lançando exceção AsaasException controlada.*

**Impacto:** falhas externas podem resultar em exceções internas não controladas.

**Arquivo:** `backend/app/Http/Controllers/BillingController.php`

---

### FINDING-003 — Dados de pagamento sem validação adequada

**Status:** Concluído
**Tasks:** KAN-04

Dados do pagamento, incluindo `amount` e informações do cartão, chegam diretamente ao fluxo de cobrança sem um Form Request dedicado.
*Correção: Criado PayBillingRequest para validar campos obrigatórios e o valor da cobrança agora provém estritamente do banco de dados (Billing).*

**Impacto:** risco de dados inválidos ou manipulados alcançarem a regra de negócio e o gateway.

**Arquivo:** `backend/app/Http/Controllers/BillingController.php`

---

### FINDING-004 — BillingController com múltiplas responsabilidades

**Status:** Concluído
**Tasks:** KAN-05 / KAN-06

O controller concentra integração HTTP, regra de negócio e persistência.
*Atualização KAN-06: A regra de negócio financeira e persistência foram extraídas para o BillingPaymentService, deixando o BillingController responsável apenas pela interface HTTP e orquestração.*

**Impacto:** alto acoplamento e baixa testabilidade.

**Arquivo:** `backend/app/Http/Controllers/BillingController.php`

---

### FINDING-005 — Ausência de controle de concorrência no pagamento

**Status:** Concluído
**Tasks:** KAN-07

**Impacto:** Risco de cobranças duplicadas no gateway em requisições simultâneas.

**Solução e Justificativa:**
- Utilizado `DB::transaction()` com `lockForUpdate()` no registro de Billing para impedir race conditions locais.
- Sem retry financeiro para ConnectionException a fim de evitar duplicação (se transacionado no gateway).
- Trata-se de mitigação local. Evolução para a arquitetura seria o uso de idempotency keys/webhooks de reconciliação.

**Arquivos alterados:**
- `backend/app/Services/BillingPaymentService.php`

---

### FINDING-006 — Exposição de dados de cartão de crédito

**Status:** Concluído
**Tasks:** KAN-08

**Impacto:** Risco de segurança e conformidade (exposição de PAN, CVV e Token de cobrança do cliente via payload de respostas JSON da API e potencial persistência insegura).

**Solução e Justificativa:**
- Os models e serviços garantem que os campos `card_number` (PAN completo) e `cvv` não sejam mapeados para persistência em Banco de Dados, extraindo da API parceira estritamente a máscara (`card_last_four`), nome (`card_holder_name`) e `card_brand`.
- O `card_token` mantido na modelagem original foi preservado por questões de schema, porém tratado como dado sensível, incluído no vetor `$hidden` do `CreditCard` Model, inviabilizando que resvale em endpoints como o `GET /api/billing/{id}`.

**Arquivos alterados:**
- `backend/app/Models/CreditCard.php`

---

## Correções realizadas

### KAN-02 — Baseline PostgreSQL

**Problema:** imagem PostgreSQL sem versão fixada e porta `5432` obrigatória no host.

**Correção:**
- fixada a imagem em `postgres:16`;
- porta externa configurável por `${POSTGRES_PORT:-5432}`.

**Resultado:** o padrão continua sendo `5432`, com possibilidade de override local sem alterar o `docker-compose.yml`.

**Arquivo:** `docker-compose.yml`

---

## Resumo

| ID | Descrição | Status | Task |
|---|---|---|---|
| FINDING-001 | Configuração Asaas hardcoded | Concluído | KAN-03 |
| FINDING-002 | Resposta do gateway não validada | Concluído | KAN-05 |
| FINDING-003 | Validação do pagamento | Concluído | KAN-04 |
| FINDING-004 | Responsabilidades do BillingController | Concluído | KAN-05 / KAN-06 |
| FINDING-005 | Ausência de controle de concorrência | Concluído | KAN-07 |
| FINDING-006 | Exposição de dados de cartão | Concluído | KAN-08 |
| KAN-02 | Baseline PostgreSQL | Concluído | KAN-02 |