<div align="center">

# ⚡ SupportFlow

### Organize atendimentos. Acompanhe pendências. Não perca retornos.

**Um painel pessoal para transformar conversas dispersas em um fluxo de suporte organizado, rastreável e fácil de acompanhar.**

<p>
  <img alt="Laravel 13" src="https://img.shields.io/badge/Laravel-13-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" />
  <img alt="Vue.js 3" src="https://img.shields.io/badge/Vue.js-3-42B883?style=for-the-badge&logo=vuedotjs&logoColor=white" />
  <img alt="MySQL 8.4" src="https://img.shields.io/badge/MySQL-8.4-4479A1?style=for-the-badge&logo=mysql&logoColor=white" />
  <img alt="Vite" src="https://img.shields.io/badge/Vite-Frontend-646CFF?style=for-the-badge&logo=vite&logoColor=white" />
</p>

**Projeto pessoal • API + SPA • Foco em produtividade no suporte técnico**

</div>

---

## 💡 O problema que deu origem ao projeto

No suporte técnico, nem todo atendimento termina quando a conversa acaba.

Um cliente chama pelo WhatsApp, um consultor precisa verificar uma situação, outro setor fica responsável por uma resposta e, enquanto isso, novos atendimentos continuam chegando. Sem um acompanhamento centralizado, é fácil perder o contexto, esquecer retornos ou deixar pendências sem resolução.

O **SupportFlow** nasceu para resolver esse problema do dia a dia: reunir atendimentos, históricos, responsáveis pelo retorno e informações úteis **em um só lugar**.

> **A ideia é simples:** menos tempo tentando lembrar o que ficou pendente e mais tempo trabalhando na solução.

## ✨ Funcionalidades

| Recurso                              | O que permite fazer                                                                                              |
| :----------------------------------- | :--------------------------------------------------------------------------------------------------------------- |
| 🗂️ **Gestão de atendimentos**        | Registrar e acompanhar cada interação de suporte com seu próprio histórico e situação.                           |
| 👤 **Contatos e clientes separados** | Identificar quem entrou em contato e, quando aplicável, qual cliente ou empresa está relacionado ao atendimento. |
| 🧩 **Sistemas atendidos**            | Associar cada atendimento a um sistema e desativar sistemas sem perder o histórico.                              |
| 🔗 **Atendimentos relacionados**     | Conectar novos contatos a atendimentos anteriores sobre o mesmo problema, sem misturar seus encerramentos.       |
| 📌 **Pendências e encaminhamentos**  | Manter visível o que ainda depende de retorno, análise ou ação de terceiros.                                     |
| ⏰ **Lembretes internos**            | Organizar retornos de hoje, atrasados e próximos — vinculados a atendimentos ou independentes.                   |
| 📚 **Consulta de soluções**          | Centralizar informações úteis para lidar com problemas recorrentes.                                              |
| ✅ **Encerramento com contexto**     | Alertar sobre pendências abertas e registrar como a resolução foi confirmada.                                    |

### 🔄 Como funciona na prática

```text
Contato recebido
       │
       ▼
Registrar atendimento
       │
       ▼
Identificar sistema, contato e cliente (se houver)
       │
       ▼
Investigar e documentar o problema
       │
       ├──► Criar pendência / encaminhamento
       │                │
       │                ▼
       │         Agendar lembrete
       │                │
       │                ▼
       │         Acompanhar retorno
       │                │
       └────────────────┘
       │
       ▼
Registrar resolução e concluir atendimento
```

## 🎯 O que torna o SupportFlow diferente?

O projeto foi pensado para **uso individual**, a partir de situações reais da rotina de suporte. Por isso, prioriza rastreabilidade e praticidade em vez de processos complexos de uma central multiusuário.

- **Um novo contato é um novo atendimento:** mesmo quando trata de um problema antigo, o histórico fica relacionado sem perder a individualidade de cada registro.
- **Uma pendência não desaparece com o encerramento:** o sistema alerta sobre itens abertos e permite encerrar de forma consciente.
- **A resolução pode ser confirmada por diferentes fontes:** cliente ou setor responsável, conforme o caso.
- **O histórico importa:** sistemas inativos continuam identificados nos atendimentos anteriores.

> **Nota:** o SupportFlow não é uma integração automática com o WhatsApp. O registro e o acompanhamento são feitos pelo usuário no painel, e os lembretes são internos, sem notificações externas nesta fase.

## 🏗️ Tecnologias e arquitetura

| Camada                 | Tecnologia                        |
| :--------------------- | :-------------------------------- |
| **Backend / API**      | PHP + Laravel 13                  |
| **Frontend**           | Vue 3 + Vite                      |
| **Banco de dados**     | MySQL 8.4                         |
| **Comunicação**        | API HTTP entre frontend e backend |
| **Controle de versão** | Git + GitHub                      |

**Organização geral do repositório:**

```text
supportflow/
├── app/                 # Lógica da aplicação Laravel
├── database/            # Migrations e seeders
├── routes/              # Rotas da aplicação e API
├── tests/               # Testes do backend
├── frontend/            # Aplicação Vue 3 / Vite
├── composer.json
└── README.md
```

## 🚀 Executando localmente

### Pré-requisitos

- PHP e Composer compatíveis com a versão do Laravel do projeto.
- Node.js e npm.
- MySQL 8.4 (ou versão compatível).
- Git.

Os exemplos abaixo usam **PowerShell no Windows 11**. Execute os comandos a partir da pasta raiz do projeto, exceto quando indicado.

### 1. Preparar o backend

```powershell
composer install
if (-not (Test-Path .env)) {
    Copy-Item .env.example .env
    php artisan key:generate
}
```

> Se o arquivo `.env` já existir, ele será preservado. Se estiver sem `APP_KEY`, execute `php artisan key:generate` apenas após verificar a configuração existente.

Crie um banco de dados vazio chamado `supportflow` e ajuste as configurações no arquivo `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=supportflow
DB_USERNAME=seu_usuario
DB_PASSWORD=sua_senha
```

Depois, execute:

```powershell
php artisan migrate
php artisan serve
```

Por padrão, o servidor do Laravel fica disponível em `http://127.0.0.1:8000`.

### 2. Iniciar o frontend

Abra **outro terminal**:

```powershell
cd frontend
npm install
npm run dev
```

Abra no navegador o endereço mostrado pelo Vite no terminal. Caso o frontend utilize uma variável de ambiente para a URL da API, configure-a conforme o arquivo de exemplo ou a configuração existente no projeto.

### 3. Popular dados de demonstração (opcional)

Se os seeders de teste estiverem configurados no projeto, execute na raiz:

```powershell
php artisan db:seed
```

**⚠️ Atenção:** execute seeders e operações de limpeza apenas em um banco de desenvolvimento. Confira o conteúdo dos seeders antes de rodá-los em um banco com dados que precisam ser preservados.

## 🧪 Qualidade e testes

O desenvolvimento inclui verificações automatizadas de backend e frontend, além de testes dos fluxos de uso.

Na raiz do projeto:

```powershell
php artisan test
```

No frontend:

```powershell
cd frontend
npm run build
```

Para consultar os demais comandos de teste e validação disponíveis no frontend, utilize `npm run`.

## 🛣️ Próximos passos

O SupportFlow é um projeto em evolução. As prioridades futuras podem incluir melhorias na experiência do painel, relatórios e indicadores, novas formas de busca no histórico e outras automações que façam sentido no uso diário — sem perder a simplicidade que motivou sua criação.

## 👨‍💻 Sobre o projeto

O SupportFlow é um **projeto pessoal de desenvolvimento full stack**, criado para colocar em prática conhecimentos de Laravel, Vue e modelagem de dados enquanto resolve uma necessidade concreta de organização no suporte técnico.

Seu propósito vai além de registrar chamados: é construir um fluxo de trabalho mais claro, consistente e confiável.

---

<div align="center">

### ⚡ SupportFlow

**Cada atendimento tem uma história. Cada pendência merece acompanhamento.**

_Desenvolvido a partir de problemas reais, para tornar o suporte mais organizado._

</div>
