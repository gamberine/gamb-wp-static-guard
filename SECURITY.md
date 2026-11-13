# Política de segurança

## Versão mantida

A linha 4.x é a referência para novas instalações. As versões 3.0.0 e 3.0.1 são registros históricos e não recebem recomendação de uso.

## Comunicação de vulnerabilidades

Utilize o recurso privado de comunicação de vulnerabilidades do repositório, quando disponível. Não abra uma issue pública contendo credenciais, dados pessoais, cópias SQL, conteúdo reservado ou uma exploração operacional de instalação ativa. Na indisponibilidade desse recurso, solicite ao administrador do repositório um canal reservado antes de encaminhar evidências sensíveis.

O relato deve conter versão afetada, requisitos de reprodução, comportamento observado, impacto e evidências reduzidas ao necessário. A classificação considera severidade, confiança, contexto de exposição e eventual CWE aplicável.

## Limites de proteção

- Tokens de recursos não constituem autorização para documentos privados.
- A saída estática deve ser servida por configuração compatível com rotas, tipos MIME e bloqueios gerados.
- Cabeçalhos adicionados pelo servidor ou proxy podem exigir configuração própria, fora do alcance do PHP.
- Remoção de assinaturas não demonstra impossibilidade de identificar a tecnologia utilizada.
- Validações automatizadas não equivalem a certificação integral WCAG, e-MAG ou de segurança.
- A restauração de cópias de segurança precisa ser testada pelo responsável pela instalação.

Ativações e atualizações devem ser homologadas com o tema e as extensões efetivamente utilizados.
