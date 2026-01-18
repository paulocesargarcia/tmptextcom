# Sistema de Armazenamento de Texto e Arquivos

Um sistema simples e leve para compartilhamento temporário de textos (logs/código) e arquivos, desenvolvido em PHP sem a necessidade de banco de dados.

## 🚀 Funcionalidades

- **Upload de texto ou arquivo**: Via web ou CLI (curl).
- **URLs Únicas**: Geração de URL baseada em UUID.
- **Preview Inteligente**:
  - Syntax highlight para código e logs.
  - Preview para imagens.
  - Download direto para outros tipos de arquivo.
- **Expiração Automática**: Os arquivos são removidos após 7 dias.
- **Segurança**:
  - Rate limit por IP (10 uploads por minuto).
  - Proteção contra XSS.
  - Armazenamento fora da raiz pública.
- **Sem Banco de Dados**: Utiliza apenas o sistema de arquivos local.

## 🛠️ Requisitos

- PHP 7.4 ou superior.
- Servidor Web (Apache, Nginx ou o servidor embutido do PHP).

## 📥 Instalação

1. Clone o repositório:
   ```bash
   git clone <url-do-repositorio>
   cd <nome-do-diretorio>
   ```

2. Certifique-se de que o diretório `storage` e seus subdiretórios tenham permissão de escrita para o usuário do servidor web.

## 💻 Como usar

### Servidor de Desenvolvimento (PHP)
Para rodar rapidamente:
```bash
php -S localhost:8000 -t public
```

### Via Web
Acesse `http://localhost:8000` no seu navegador para usar o formulário de upload.

### Via CLI (Execução Remota)

Você pode executar o CLI diretamente sem baixar nada, útil para servidores e automações rápidas:

```bash
# Enviar texto
bash <(curl -s "http://localhost:8000/cli.sh") -t "Olá Mundo"

# Enviar um arquivo
bash <(curl -s "http://localhost:8000/cli.sh") -f meu-arquivo.txt

# Enviar via Pipe (stdin)
cat app.log | bash <(curl -s "http://localhost:8000/cli.sh")
```

### Via CLI (Local)

Se preferir baixar o script:

```bash
curl -O http://localhost:8000/cli.sh
chmod +x cli.sh

./cli.sh -t "Texto local"
```

### Via curl (Direto)

Se preferir não usar o script:

**Texto:** `curl -X POST --data "texto" http://localhost:8000/api/upload`

**Arquivo:** `curl -F "file=@arq.txt" http://localhost:8000/api/upload`

## 📂 Estrutura do Projeto

```
/public    - Raiz do servidor web (index.php)
/src       - Lógica do sistema (Classes PHP)
/storage   - Arquivos armazenados, metadados e logs
```

## 🛡️ Segurança e Rate Limit

- O sistema limita a **10 uploads por minuto por IP**.
- Os logs de atividades podem ser encontrados em `storage/logs/app.log`.
- Nenhuma execução de script é permitida nos arquivos enviados.
