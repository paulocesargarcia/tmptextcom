#!/bin/bash

# Default URL
DEFAULT_URL="http://localhost:8000"
URL=${STORAGE_URL:-$DEFAULT_URL}

usage() {
    echo "Uso: $0 [opções]"
    echo "Opções:"
    echo "  -t, --text TEXT      Envia um texto"
    echo "  -f, --file FILE      Envia um arquivo"
    echo "  -u, --url  URL       URL do servidor (padrão: $URL)"
    echo "  -h, --help           Mostra esta ajuda"
    echo ""
    echo "Exemplos:"
    echo "  $0 -t \"Olá Mundo\""
    echo "  $0 -f documento.pdf"
    echo "  echo \"log de erro\" | $0"
    exit 1
}

while [[ "$#" -gt 0 ]]; do
    case $1 in
        -t|--text) TEXT="$2"; shift ;;
        -f|--file) FILE="$2"; shift ;;
        -u|--url)  URL="$2"; shift ;;
        -h|--help) usage ;;
        *) echo "Parâmetro desconhecido: $1"; usage ;;
    esac
    shift
done

if [ -n "$FILE" ]; then
    if [ ! -f "$FILE" ]; then
        echo "Erro: Arquivo $FILE não encontrado."
        exit 1
    fi
    curl -s -F "file=@$FILE" "$URL/api/upload"
elif [ -n "$TEXT" ]; then
    curl -s -X POST --data "$TEXT" "$URL/api/upload"
else
    # Tenta ler do stdin se não houver tty
    if [ ! -t 0 ]; then
        curl -s -X POST --data-binary @- "$URL/api/upload"
    else
        echo "Erro: Nenhum conteúdo para enviar."
        usage
    fi
fi
echo ""
