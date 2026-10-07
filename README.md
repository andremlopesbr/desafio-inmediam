# Desafio InMediam

## Sobre o desafio

Bem-vindo ao desafio técnico da **InMediam**!

Neste desafio, você receberá um projeto já existente que simula uma **tela de pagamento de assinatura**. O sistema permite que um usuário visualize os **detalhes** de uma cobrança e realize o pagamento com cartão de crédito, integrado com a **API do Asaas** (sandbox).

Porém, o código possui **diversos problemas**: bugs, falhas de segurança, más práticas de desenvolvimento e ausência de validações. Seu objetivo é **identificar e corrigir** o máximo de problemas que conseguir.

> Este desafio simula um cenário real de manutenção de código legado, algo comum no dia a dia de qualquer desenvolvedor.

# Tecnologias

## Backend

* **PHP 8.2+** com **Laravel 12**
* **PostgreSQL 16**
* **API do Asaas** (sandbox) para processamento de pagamentos

## Frontend

* **React 18** com **TypeScript**
* **Vite** como bundler
* **@inmediam/ui** (biblioteca de componentes interna)
* **TanStack Query** (React Query) para gerenciamento de estado do servidor
* **React Hook Form** + **Zod** para formulários e validação
* **Axios** para requisições HTTP
* **React Router DOM** para roteamento

# Pré-requisitos

* [Docker](https://www.docker.com/) e Docker Compose
* [PHP 8.2+](https://www.php.net/) e [Composer](https://getcomposer.org/)
* [Node.js 18+](https://nodejs.org/) e npm
* [Asaas](https://sandbox.asaas.com)
* Git

# Como rodar o projeto

## 1. Clone o repositório

```bash
git clone <url-do-repositorio>
cd desafio-inmediam
```

## 2. Suba o banco de dados

```bash
docker compose up -d
```

Aguarde o container ficar **saudável** (healthcheck configurado).

## 3. Configure o backend

```bash
cd backend

# Instale as dependências
composer install

# Copie o arquivo de ambiente
cp .env.example .env

# Gere a chave da aplicação
php artisan key:generate

# Execute as migrations e seeders
php artisan migrate --seed

# Inicie o servidor
php artisan serve
```

O backend estará disponível em `http://localhost:8000`.

## 4. Configure a API do Asaas (Sandbox)

O backend utiliza a API do Asaas para processar pagamentos com cartão de crédito. Para que o fluxo de pagamento funcione:

1. Crie uma conta no ambiente do Asaas em [https://sandbox.asaas.com](https://sandbox.asaas.com)
2. Conclua a criação da conta, enviando documentos necessários
3. Acesse **Configurações > Integrações > API** e copie sua chave de API
4. A chave de API precisa ser configurada no projeto (dica: procure onde ela está sendo utilizada no código)

> **Nota:** A API sandbox do Asaas aceita dados fictícios de cartão para testes. Consulte a [documentação do Asaas](https://docs.asaas.com) para mais detalhes sobre os endpoints utilizados.

## 5. Configure o frontend

```bash
cd frontend

# Instale as dependências
npm install

# Inicie o servidor de desenvolvimento
npm run dev
```

O frontend estará disponível em `http://localhost:3000`.

## 6. Acesse a aplicação

Abra o navegador e acesse:

```
http://localhost:3000
```

Você verá a página inicial com links para as cobranças disponíveis. Clique em uma cobrança pendente para visualizar a tela de pagamento.

# Estrutura do projeto

```
desafio-inmediam/
├── docker-compose.yml       # PostgreSQL via Docker
├── backend/                 # API Laravel
│   ├── app/
│   │   ├── Http/Controllers/
│   │   └── Models/
│   ├── database/
│   │   ├── migrations/
│   │   └── seeders/
│   └── routes/
│       └── api.php
└── frontend/                # SPA React
    └── src/
        ├── components/      # Componentes reutilizáveis
        ├── lib/             # Configurações (react-query)
        ├── utils/           # Utilitários (formatação)
        └── pages/           # Páginas da aplicação
            ├── home.tsx
            └── billing/
                ├── billing.tsx
                └── components/
                    └── payment-form.tsx
```

# Endpoints da API

| Método | Rota                    | Descrição                            |
| ------ | ----------------------- | ------------------------------------ |
| GET    | `/api/billing/{id}`     | Retorna os detalhes de uma cobrança  |
| POST   | `/api/payment/{id}`     | Processa o pagamento de uma cobrança |

# Dados de teste (Seeds)

O seeder cria os seguintes dados:

## Planos

| ID  | Nome         | Preço     |
| --- | ------------ | --------- |
| 1   | Básico       | R$ 29,90  |
| 2   | Profissional | R$ 79,90  |
| 3   | Empresarial  | R$ 199,90 |

## Cobranças

| ID  | Cliente        | Plano        | Status   |
| --- | -------------- | ------------ | -------- |
| 1   | João da Silva  | Profissional | Pendente |
| 2   | Maria Oliveira | Básico       | Pago     |

# O que esperamos

O código possui problemas intencionais em **ambas as camadas** (frontend e backend). Seu trabalho é:

## 1. Identificar e corrigir bugs

* Corrija erros de lógica, warnings do React e comportamentos inesperados
* Documente brevemente o que encontrou e por que corrigiu

## 2. Melhorar a segurança

* Identifique e corrija vulnerabilidades de segurança
* Validações adequadas nos dados de entrada
* Verifique como credenciais e configurações sensíveis são tratadas

## 3. Aplicar boas práticas

* Refatore o código seguindo princípios de clean code
* Separe responsabilidades onde necessário (ex: Service layer, Form Requests, camada de API)
* Tratamento de erros adequado
* Centralize configurações que estão espalhadas pelo código

## 4. Melhorar a experiência do usuário

* Estados de carregamento e tratamento de erros na interface
* Garantir o feedback ao usuário seja correto e consistente

## 5. Melhorar a resiliência

* Cenários de falha em integrações externas
* Mecanismos de proteção contra operações duplicadas

# Entrega

1. Faça um **fork** deste repositório
2. Crie uma branch com seu user do GitHub: `feature/seu-username`
3. Realize as correções e melhorias
4. Crie um arquivo `CORRECOES.md` na raiz do projeto documentando:
   * O que você encontrou
   * O que você corrigiu
   * Por que fez cada correção
5. Abra um **Pull Request** para a branch `main`

# Tempo estimado

O desafio foi projetado para ser completado em **2 a 3 horas**. Não se preocupe em encontrar absolutamente tudo, queremos entender seu raciocínio e como você aborda problemas em código existente.

---

Boa sorte! Estamos ansiosos para ver como você aborda este desafio.
