# Registo de presenças

PWA em PHP, MySQL e JavaScript para treinadores. A lista de atletas é partilhada entre as contas autorizadas. Cada atleta tem um estado por dia de treino, segundo o fuso horário de Lisboa. Um toque grava o estado no servidor. A aplicação confirma a gravação e apresenta uma mensagem quando perde a ligação.

## Instalar na WebTuga

1. Crie uma pasta da aplicação fora da raiz do domínio principal, por exemplo `~/attendance-pwa`. Copie os ficheiros do projeto para essa pasta.
2. Em **Domínios** no cPanel, configure `presencas.heldermdmartins.com` com raiz independente apontada exclusivamente para `~/attendance-pwa/public`. Ative o certificado e o redirecionamento HTTPS. Se o plano exigir uma raiz diferente, mantenha a mesma separação entre `public/` e os ficheiros privados antes de publicar.
3. Selecione PHP 8.2 ou superior com `pdo_mysql` e `mbstring`. Desative `display_errors` em produção.
4. Crie uma base de dados MySQL ou MariaDB e importe `database/schema.sql` pelo phpMyAdmin. Crie um utilizador dedicado à aplicação com permissões SELECT, INSERT, UPDATE e DELETE apenas nessa base.
5. Copie `.env.example` para `.env` na pasta da aplicação e preencha as credenciais no servidor. Use aspas duplas para valores com espaços ou ponto e vírgula. Mantenha `APP_LOCAL_HTTP=0` e limite a leitura do ficheiro à conta de alojamento (`0600`, quando o PHP corre com essa conta).
6. No Terminal do cPanel, na pasta da aplicação, crie uma conta. Exemplo para Bash, com leitura silenciosa da palavra-passe:

   ```sh
   read -rs -p 'Palavra-passe: ' attendance_password
   printf '\n'
   printf '%s\n' "$attendance_password" | php bin/manage.php coach treinador
   unset attendance_password
   ```

   Se o plano não tiver Terminal, gere o hash localmente com `password_hash` usando a mesma leitura silenciosa e crie a conta na tabela `coaches` pelo phpMyAdmin.

7. Adicione os atletas diretamente na tabela `athletes` pelo phpMyAdmin. Guarde cada retrato WebP em `storage/photos/<id-do-atleta>.webp`, fora de `public/`. Permissões sugeridas quando o PHP corre com a conta de alojamento são `0700` para as pastas privadas e `0600` para as fotografias. O cartão funciona também sem retrato.
8. Teste a entrada e a gravação com um atleta fictício antes de carregar dados reais. No telemóvel, abra o endereço em HTTPS e use a opção de instalação do navegador ou **Adicionar ao ecrã principal** no Safari.

## Desenvolvimento local

Use uma base de dados de teste vazia, importe o esquema e preencha um `.env` local com `APP_LOCAL_HTTP=1`. Execute `php -S 127.0.0.1:8080 -t public`. Crie uma conta de teste como descrito acima e adicione um atleta fictício com `php bin/manage.php athlete 'Atleta de teste'`.

## Dados privados

A base de dados, os retratos e o ficheiro `.env` ficam no servidor e fora da raiz pública. O Git aceita apenas os caminhos de código indicados em `.gitignore`. Mantenha exportações, cópias de segurança e credenciais fora do repositório. As fotografias são servidas por PHP depois de validar a sessão. A PWA não guarda a lista, as fotos nem o estado das presenças em cache e precisa de ligação para gravar. Para revogar uma conta, defina `active=0` na tabela `coaches`.

## Gestão de utilizadores

Em instalações existentes, importe primeiro `database/add-user-management.sql` com uma conta de manutenção com permissão para alterar a tabela. As contas atuais mantêm o nome de utilizador, a palavra-passe, as presenças e o acesso à grelha. Recebem inicialmente o perfil Utilizador e a posição Treinador.

Crie uma conta separada para administração repetindo a leitura silenciosa de palavra-passe descrita acima e usando `php bin/manage.php admin UTILIZADOR`. Para uma conta ativa existente, use `php bin/manage.php promote-admin UTILIZADOR`. Depois de entrar, abra **Gerir utilizadores** para criar, editar ou desativar contas. A posição Treinador ou Delegado descreve a função no clube. Ambos os perfis acedem à grelha de presenças. Os contactos são visíveis na área reservada aos administradores.

## Gestão de atletas

Em instalações existentes, aplique `database/add-athlete-details.sql` antes de atualizar a aplicação. A migração mantém os IDs dos atletas e as presenças existentes. Na área privada, um administrador pode criar atletas, editar os dados e gerir retratos. O carregamento aceita JPEG, PNG e WebP e requer a extensão GD com suporte WebP. O ficheiro é convertido no servidor e guardado em `storage/photos/`, fora da raiz pública.

## Calendário e histórico

O calendário de treinos está definido em `app/training-schedule.php`. A grelha abre no treino de hoje, quando houver, ou no próximo treino. As marcações são feitas no próprio dia, no fuso horário de Lisboa. A consulta por data apresenta os registos guardados, incluindo os anteriores à introdução do calendário.

Em instalações que antecedem o acesso por email, aplique `database/use-email-login.sql` com uma conta de manutenção. Resolva primeiro eventuais emails duplicados. As contas com email passam a usá-lo para entrar, com a mesma palavra-passe. Para contas antigas sem email, o identificador anterior mantém-se até ser preenchido o campo **Email de acesso**.

Para ativar cancelamentos numa instalação existente, aplique `database/add-training-cancellations.sql` antes de atualizar. Os administradores podem cancelar ou reativar o treino de hoje e os próximos treinos apresentados. Um cancelamento exige motivo e suspende novas marcações. Os registos já guardados ficam disponíveis para consulta.

## Registo de atividade

Em instalações existentes, aplique `database/add-activity-log.sql` antes de atualizar. O menu **Registo de atividade** está reservado a administradores e mostra inícios de sessão bem-sucedidos e alterações de presença a partir da instalação desta versão. As horas são guardadas em UTC e apresentadas no fuso horário de Lisboa.

Em instalações existentes, aplique `database/add-coach-attendance.sql` para ativar as presenças dos treinadores. A grelha do dia inclui contas ativas com a posição Treinador. O histórico mantém as marcações dos treinadores mesmo depois de a conta ficar inativa.

## Manter sessão iniciada

Em instalações existentes, aplique `database/add-remember-login.sql` antes de atualizar. A opção no formulário de entrada permite retomar a sessão no mesmo navegador durante 30 dias. Sair revoga a sessão memorizada nesse navegador. Mudar a palavra-passe invalida as sessões memorizadas dessa conta.
