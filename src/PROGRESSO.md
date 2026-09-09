# Progresso do Projeto — Oficina Mecânica (Revisão de Carros)

> Este arquivo documenta cada etapa do projeto: o que foi feito, o porquê, e as decisões tomadas. Sirva de referência sempre que tiver dúvida sobre "por que fizemos assim". Continuação atual: **Codex**, com aprendizado guiado passo a passo.

---

## Visão geral do sistema

Sistema de revisão de carros estilo oficina mecânica, com:
- Cadastro de clientes (`pessoa`)
- Cadastro de carros (`carro`)
- Cadastro de marcas (`marca`, tabela fixa)
- Cadastro de revisões (`revisao`)
- Relatórios sobre revisões, clientes e carros (com SQLs prontos)

**Stack:** PHP Laravel + PostgreSQL + Redis + Docker

**Perfil do desenvolvedor:** iniciante, primeiro projeto real. Prefere aprender **passo a passo**, entendendo o "porquê" de cada decisão técnica antes de agir — não apenas copiar código pronto. Ao continuar no Cursor, manter esse estilo: explicar o motivo de cada comando/config antes do código, confirmar entendimento, e só entregar código pronto para partes repetitivas.

---

## Estrutura de pastas atual

```
oficina-mecanica/
├── docker/
│   └── php/
│       └── Dockerfile
├── src/                  ← projeto Laravel (já criado, migrations já rodadas)
├── docker-compose.yml
├── .gitignore
└── PROGRESSO.md
```

---

## Etapa 1 — Entendendo as tecnologias

| Peça | Papel | Analogia |
|---|---|---|
| Laravel | Recebe pedidos, decide o que fazer, fala com o banco | O "cérebro" da oficina |
| PostgreSQL | Guarda os dados permanentemente | O fichário/arquivo |
| Redis | Guarda respostas de relatórios pesados, temporariamente | Alguém que decorou as respostas mais pedidas |
| Docker | Empacota tudo isso de forma padronizada | A "caixa" onde tudo é montado igual em qualquer computador |

---

## Etapa 2 — `docker-compose.yml` (versão final, sem `version` obsoleta)

```yaml
services:
  app:
    build:
      context: ./docker/php
    container_name: oficina_app
    working_dir: /var/www
    volumes:
      - ./src:/var/www
    ports:
      - "8000:8000"
    depends_on:
      - db
      - redis
    networks:
      - oficina_net

  db:
    image: postgres:16
    container_name: oficina_db
    restart: always
    environment:
      POSTGRES_DB: oficina
      POSTGRES_USER: oficina_user
      POSTGRES_PASSWORD: oficina_pass
    ports:
      - "5432:5432"
    volumes:
      - db_data:/var/lib/postgresql/data
    networks:
      - oficina_net

  redis:
    image: redis:7-alpine
    container_name: oficina_redis
    ports:
      - "6379:6379"
    networks:
      - oficina_net

networks:
  oficina_net:

volumes:
  db_data:
```

**Decisões-chave:**
- `db` tem volume nomeado (`db_data`) → dados permanentes, sobrevivem se o container for removido.
- `redis` **não** tem volume, de propósito → guarda só cache recalculável.
- **Nenhuma senha configurada para o Redis** neste compose — importante para o `.env` (ver Etapa 5).
- `app` usa `build`, não `image`, porque não existe imagem pronta com o que precisamos.
- `volumes: ./src:/var/www` espelha a pasta local com o container: editar localmente reflete direto, sem rebuild.

---

## Etapa 3 — `docker/php/Dockerfile`

```dockerfile
FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    git curl zip unzip libpq-dev libzip-dev \
    && docker-php-ext-install pdo pdo_pgsql zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
```

- `pdo` = interface genérica do PHP para bancos de dados; `pdo_pgsql` = dialeto específico do Postgres (sem ele, sem conexão possível).
- A extensão `phpredis` **não** foi instalada aqui — por isso o projeto usa `predis` (biblioteca via Composer) em vez de `phpredis` (extensão nativa). Migrar para `phpredis` no futuro exigiria adicionar `redis` ao `docker-php-ext-install` e rebuildar.

---

## Etapa 4 — Build e criação do Laravel

```bash
docker compose build
docker compose run --rm app composer create-project laravel/laravel .
```

- `docker compose build` → constrói só a imagem do `app` (Postgres e Redis usam imagens prontas do Docker Hub).
- `run --rm` → cria um container **temporário**, usado porque os containers principais ainda não existiam de pé. Regra prática: *"projeto/container ainda não existe"* → `run`; *"container já está rodando"* → `exec` (ver Etapa 7).

**Erro pontual encontrado e resolvido:** `500 Internal Server Error` no pipe do Docker Desktop durante o primeiro `build` — resolvido rodando o comando novamente (Docker Engine ainda estabilizando).

---

## Etapa 5 — Configuração do `.env` (valores finais corretos)

```env
DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=oficina
DB_USERNAME=oficina_user
DB_PASSWORD=oficina_pass

CACHE_STORE=redis
REDIS_CLIENT=predis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379
```

Por que `DB_HOST=db` e `REDIS_HOST=redis`, não `localhost`: cada container é isolado; `localhost` dentro de um container significa "eu mesmo", não o container vizinho. Os containers se enxergam pelo **nome do serviço** dentro da rede `oficina_net`.

**Três erros encontrados e já corrigidos (não repetir):**
1. Espaço em branco antes de `DB_PASSWORD` — quebra o parsing do `.env`.
2. `CACHE_STORE=database` em vez de `redis` — cache caindo no Postgres em vez do Redis.
3. `REDIS_PASSWORD=redis`, mas o Redis do compose não tem senha configurada — corrigido para a palavra literal `null`.

**Decisão: `predis` em vez de `phpredis`** — biblioteca PHP pura via Composer, mais simples nesta fase (não exige mexer no Dockerfile). Instalado com:
```bash
docker compose run --rm app composer require predis/predis
```

---

## Etapa 6 — Diagnóstico do "carregamento lento" em localhost:8000

**Sintoma:** página demorava 1-2 minutos pra carregar, mas terminava com sucesso nos logs.

**Hipótese levantada:** no Windows, o navegador tenta resolver `localhost` primeiro via IPv6 (`::1`) antes de cair para IPv4 (`127.0.0.1`); se o Docker só expõe a porta corretamente via IPv4, o navegador espera o timeout do IPv6 antes de conectar. **Ainda não confirmada explicitamente pelo usuário** (testar `http://127.0.0.1:8000` e comparar tempos, se o problema voltar a ocorrer).

---

## Etapa 7 — Erro "relation sessions does not exist" e correção

Ao acessar a aplicação, apareceu:
```
SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "sessions" does not exist
```

**Causa:** `SESSION_DRIVER=database` no `.env` (padrão do Laravel) exige uma tabela `sessions` no Postgres — mas **nenhuma migration havia sido executada ainda**. As migrations "de fábrica" do Laravel (`users`, `cache`, `sessions`, `jobs`) existiam só como arquivos PHP, não como tabelas reais.

**Correção:**
```bash
docker compose exec app php artisan migrate
```

**Por que `exec` e não `run --rm` aqui:** o container `app` já estava rodando (subido com `docker compose up -d`). `exec` executa o comando **dentro de um container já ativo**; `run --rm` criaria um container novo e descartável, desnecessário nesse caso.

> **Status ao migrar para o Cursor:** o comando `php artisan migrate` foi indicado como próximo passo — confirmar no Cursor se ele já rodou sem erro e se a página `localhost:8000` carrega normalmente (tela de boas-vindas do Laravel) antes de prosseguir.

---

## Etapa 8 — Git / GitHub

Repositório único na **raiz** do projeto (não dentro de `src/`), pois há arquivos fora do Laravel.

`.gitignore` da raiz:
```
src/vendor/
src/.env
src/node_modules/
src/storage/*.key
src/storage/logs/*.log

.DS_Store
.vscode/
```

Fluxo padrão: `git add .` → `git commit -m "mensagem"` → `git push`.

**Status:** `.gitignore` criado. `git init` e primeiro commit/push ainda precisam ser confirmados/feitos — verificar no Cursor antes de prosseguir.

---

## Checklist geral

- [x] Docker Compose e Dockerfile criados e explicados
- [x] `docker compose build` executado com sucesso
- [x] Laravel criado via `composer create-project`
- [x] `.env` configurado e corrigido (banco + Redis)
- [x] `predis/predis` instalado
- [x] `docker compose up -d` executado — 3 containers `Up`
- [x] Diagnóstico do carregamento lento (hipótese IPv6, não confirmada)
- [x] Erro de `sessions` diagnosticado; `php artisan migrate` indicado como correção
- [ ] Confirmar que `php artisan migrate` rodou sem erro e a página carrega normalmente
- [x] Confirmar `git init` e commits locais (ver Etapa 9)
- [ ] Enviar o commit da estrutura Laravel ao GitHub e confirmar o push
- [x] Criar migrations específicas do domínio: `marca`, `pessoa`, `carro`, `revisao` (arquivos concluídos; ver Etapa 11)
- [ ] Aplicar e confirmar as migrations do domínio no PostgreSQL do projeto
- [ ] Criar models com relacionamentos Eloquent (`Marca`, `Pessoa`, `Carro`, `Revisao`)
- [ ] Seeder de marcas (lista fixa: Chevrolet, Volkswagen, Fiat, Ford, Toyota, Honda, Hyundai, Renault)
- [ ] Controllers CRUD (`PessoaController`, `CarroController`, `RevisaoController`)
- [ ] Rotas em `routes/api.php`
- [ ] Relatórios com cache no Redis (`RelatorioController`): revisões por marca, clientes com mais revisões, faturamento mensal
- [ ] SQLs prontos para consultas/relatórios (já esboçados, ver seção de referência abaixo)

---

## Referência rápida: SQLs de relatório já esboçados

```sql
-- Total de revisões por marca
SELECT m.nome AS marca, COUNT(r.id) AS total_revisoes
FROM revisao r
JOIN carro c ON c.id = r.carro_id
JOIN marca m ON m.id = c.marca_id
GROUP BY m.nome
ORDER BY total_revisoes DESC;

-- Clientes que mais revisaram
SELECT p.nome, COUNT(r.id) AS total_revisoes
FROM revisao r
JOIN pessoa p ON p.id = r.pessoa_id
GROUP BY p.nome
ORDER BY total_revisoes DESC
LIMIT 10;

-- Carros que nunca fizeram revisão
SELECT c.placa, c.modelo, p.nome AS dono
FROM carro c
JOIN pessoa p ON p.id = c.pessoa_id
LEFT JOIN revisao r ON r.carro_id = c.id
WHERE r.id IS NULL;

-- Faturamento total por status de revisão
SELECT status, SUM(valor) AS total
FROM revisao
GROUP BY status;
```

---

## Comandos de referência rápida

```bash
# Build e criação inicial
docker compose build
docker compose run --rm app composer create-project laravel/laravel .

# Instalar pacote (container ainda não precisa estar "up")
docker compose run --rm app composer require <pacote>

# Subir o ambiente
docker compose up -d

# Ver status dos containers
docker compose ps

# Ver logs do Laravel
docker compose logs app

# Rodar comando artisan/migration (container já rodando)
docker compose exec app php artisan migrate
docker compose exec app php artisan make:model NomeDoModel -m
docker compose exec app php artisan make:controller NomeController --resource --model=NomeDoModel
docker compose exec app php artisan make:seeder NomeSeeder
docker compose exec app php artisan db:seed --class=NomeSeeder
```

---

*Continue atualizando este arquivo a cada etapa concluída no Cursor — é o registro de aprendizado do projeto, não só do código.*

---

## Etapa 9 — Continuação guiada e registro do Laravel no Git

Este documento foi recuperado do PROGRESSO.md fornecido pelo usuário. A cópia de trabalho fica em `src/PROGRESSO.md`, que estava vazia. As etapas anteriores preservam o histórico recebido; esta seção atualiza o estado observado no repositório.

### Forma de trabalho combinada

- Explicar brevemente o objetivo e o motivo de cada etapa, mantendo o aprendizado guiado.
- O usuário continua executando os comandos e escrevendo o código para aprender. O assistente orienta e realiza as consultas necessárias para verificar o resultado, sem assumir a execução das etapas de implementação.
- Não pedir ao usuário que copie saídas de terminal ou arquivos que o assistente consegue consultar. Conferir diretamente os arquivos, o estado do Git, os logs e outros resultados acessíveis no ambiente.
- Ao identificar um erro durante a conferência, apontá-lo e explicar sua causa e a correção antes de avançar.
- A conferência depende de uma consulta ativa do assistente: ele não recebe automaticamente a saída do terminal do usuário nem monitora o ambiente entre mensagens. Quando necessário, basta o usuário avisar que executou; solicitar a mensagem de erro apenas se ela não estiver acessível por outros meios.
- Manter o ritmo guiado original: explicar antes dos comandos e confirmar o entendimento antes de passar para a próxima etapa.
- Mostrar o que mudou e explicar o código novo em linguagem simples. O usuário pode interromper para tirar dúvidas ou pedir mais detalhes.
- O assistente fica responsável por atualizar este PROGRESSO.md a cada etapa concluída.
- Este acordo altera apenas a exigência de compartilhar os resultados do PROMPT_CURSOR.md: a execução continua com o usuário, e o assistente consulta os resultados acessíveis diretamente. Ele substitui o acordo anterior que atribuía a execução ao assistente.

### Git confirmado

- Repositório já inicializado, na branch `main`.
- Commit `fe4fbfc`: `Configuração inicial: Docker + Laravel`. Apesar do título, registrava apenas os arquivos do Docker.
- Commit `9273230`: `Adiciona estrutura inicial do Laravel e regras do gitigore`. Confirmado no histórico local após o usuário preparar os arquivos com `git add .`.
- O segundo commit registra a estrutura do Laravel e o ajuste dos arquivos `.gitignore`.
- O `.env` e as pastas de dependências `vendor` e `node_modules` ficaram fora da lista de arquivos preparados.
- O envio do segundo commit ao GitHub ainda não foi verificado.

### Conceitos estudados

- Migration: arquivo que descreve uma alteração na estrutura do banco. Criar o arquivo não aplica a alteração; executar as migrations aplica as mudanças pendentes.
- Migrations são usadas na criação inicial das tabelas e na evolução da estrutura do banco.
- Chave primária: identificador único de um registro, como `marca.id`.
- Chave estrangeira: referência a um registro de outra tabela, como `carro.marca_id`.
- Exemplo compreendido pelo usuário: se Honda tem `id = 2`, um Civic usa `marca_id = 2`.
- `git add` prepara alterações; `git commit` registra uma versão local; o envio ao GitHub é separado.

### Próximo passo

Executar `docker compose exec app php artisan migrate`, com o container `app` ativo, para aplicar as migrations pendentes no PostgreSQL do projeto. Depois, conferir `php artisan migrate:status` no mesmo container. A criação das quatro migrations foi concluída na Etapa 11.

A execução das migrations padrão e a tela inicial funcionando foram informadas no PROMPT_CURSOR.md fornecido pelo usuário, mas não foram verificadas novamente no ambiente nesta continuação.

---

## Etapa 10 — Arquivo da migration de marca criado

- O usuário executou `docker compose exec app php artisan make:migration create_marca_table --create=marca`.
- Na primeira consulta, o arquivo ainda não estava presente. Em nova consulta, foi encontrado e lido `src/database/migrations/2026_09_09_014940_create_marca_table.php`.
- O arquivo contém a estrutura inicial esperada: `up()` com `Schema::create('marca', ...)`, `id()` e `timestamps()`; `down()` com `Schema::dropIfExists('marca')`.
- O campo `nome` foi posteriormente acrescentado pelo usuário como `$table->string('nome')->unique();` e conferido na Etapa 11.
- Foi explicado que o prefixo numérico das migrations geradas pelo Artisan representa data e hora e organiza sua ordem de execução. A descrição pode ser escrita em português; nenhum arquivo foi renomeado.
- Foi confirmada a criação do arquivo, não a execução dessa migration no banco.
- A saída do terminal do IDE não está disponível nas ferramentas desta sessão; esta verificação foi feita pela leitura direta do arquivo gerado.

---

## Etapa 11 — Quatro migrations do domínio concluídas

O usuário autorizou o assistente a gerar o código e esclareceu o escopo: **apenas as migrations de marca, pessoa, carro e revisão**. Essa autorização vale para esta etapa; a execução no banco continua com o usuário. Não foram criados models, seeders, controllers ou relatórios.

### Arquivos e campos

- `2026_09_09_014940_create_marca_table.php`: preservada a migration existente, incluindo o `nome` único que o usuário adicionou.
- `2026_09_09_014941_create_pessoa_table.php`: `id`, `nome`, `email` opcional, `telefone` opcional e timestamps.
- `2026_09_09_014942_create_carro_table.php`: `id`, `marca_id`, `pessoa_id`, `modelo`, `placa` única, `ano` e timestamps.
- `2026_09_09_014943_create_revisao_table.php`: `id`, `carro_id`, `pessoa_id`, `data_revisao`, `descricao`, `valor`, `status` e timestamps.

### Decisões de estrutura

- As tabelas permanecem no singular, conforme os documentos do projeto; as chaves estrangeiras indicam explicitamente a tabela referenciada.
- A ordem dos arquivos garante que `marca` e `pessoa` existam antes de `carro`, e que `carro` exista antes de `revisao`.
- Como os documentos não especificavam todos os campos, foram adotados campos básicos de cadastro. E-mail e telefone são opcionais; não foi imposta exclusividade de e-mail para clientes.
- A placa tem até sete caracteres, para ser armazenada sem hífen; a futura entrada de dados deverá normalizar a placa e validar seu formato.
- `valor` usa `decimal(10, 2)`, para guardar valores monetários com duas casas decimais.
- Os status adotados são `pendente`, `em_andamento`, `concluida` e `cancelada`, com `pendente` como padrão.
- `revisao.pessoa_id` identifica o cliente daquela revisão, enquanto `carro.pessoa_id` identifica o proprietário atual. A igualdade entre os dois não é imposta no banco, permitindo preservar o histórico se o carro mudar de dono.
- As relações usam `restrictOnDelete()`: é bloqueada a exclusão de marcas, clientes ou carros ainda referenciados. Isso evita a exclusão automática do histórico de revisões.
- Foram adicionados índices nas chaves estrangeiras e em `data_revisao` para apoiar consultas e os futuros relatórios.
- As migrations definem a estrutura; validações de entrada, como ano válido, valor não negativo e normalização de placa, ficam para a etapa dos cadastros.

### Verificação realizada

- PHP local: as quatro migrations passaram na verificação de sintaxe (`php -l`).
- Teste isolado com SQLite em memória: as quatro tabelas foram criadas, um cadastro relacionado foi inserido e o status padrão foi confirmado.
- Foram confirmados os bloqueios de nomes de marca e placas duplicados, referências inexistentes, status inválido e exclusão de registros vinculados.
- Os quatro métodos `down()` foram executados em ordem inversa, confirmando a remoção das tabelas no banco temporário.
- A gramática PostgreSQL do Laravel compilou 19 instruções de criação e reversão em modo de simulação, sem conectar ao PostgreSQL.
- O teste usou dependências já presentes e extensões PHP habilitadas apenas no processo de verificação. Nenhuma dependência ou configuração do projeto foi alterada.
- **As migrations ainda não foram aplicadas nem testadas no PostgreSQL do projeto nesta etapa.** Os testes em memória não substituem essa confirmação.

Referência consultada: [Migrations do Laravel 13](https://laravel.com/framework/docs/13.x/migrations).
