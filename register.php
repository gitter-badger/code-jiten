<!DOCTYPE html>
<html>
  <head>
    <?php
    require_once './vendor/autoload.php';
    require_once './models/Example.php';
    require_once './models/ExampleGroup.php';
    require_once './models/ExampleGroupMapper.php';
    require_once './models/Language.php';
    require_once './src/functions.php';
    require_once './config/database.php';

    $loader = new Twig_Loader_Filesystem('views');
    $twig = new Twig_Environment($loader, array(
      //'cache' => './compilation_cache',
      'debug' => true,
    ));

    $twig->addExtension(new Twig_Extension_Debug());
    $template = $twig->load('header.html.twig');
    echo $template->render();

    $db = new PDO(PDO_DSN, DB_USERNAME, DB_PASSWD);
    $group_cd = isset($_GET['group_cd']) ? $_GET['group_cd'] : null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      if(isset($_GET['group_cd'])) {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $insert_stmt = $db->prepare("INSERT INTO t_example (\"language\", \"example\", \"group_cd\") VALUES
(:language, :example, :group_cd)");
        $update_stmt = $db->prepare("UPDATE t_example SET language = :language, example = :example
WHERE example_id = :example_id;");
        if (!empty($_POST['items'])) {
          foreach ($_POST['items'] as $row) {
            if ($row['insert_flag'] == "true") {
              $insert_stmt->bindParam(':language',$row['example']['language']);
              $insert_stmt->bindParam(':example', $row['example']['example']);
              $insert_stmt->bindParam(':group_cd',intval($row['group_cd']));
              $insert_stmt->execute();
            } else {
              $update_stmt->bindParam(':language',$row['example']['language']);
              $update_stmt->bindParam(':example', $row['example']['example']);
              $update_stmt->bindParam(':example_id',intval($row['example']['example_id']));
              $update_stmt->execute();
            }
          }
        }
        if (!empty($_POST['delete_target'])) {
          $delete_stmt = $db->prepare('DELETE FROM t_example WHERE example_id = :example_id;');
          foreach($_POST['delete_target'] as $example_id){
            $delete_stmt->bindParam(':example_id', intval($example_id));
            $delete_stmt->execute();
          }
        }

      }
    }
    $_POST = array();
    $json = '';
    $examples = '';
    $disp_group = '';
    $group_name = null;
    $group_data_json = '';

    if(isset($group_cd)) {

      // example data
      $examples_stmt = $db->prepare("SELECT example_id, language, example, group_cd, group_name FROM v_example_desc WHERE group_cd = :group_cd;");
      $examples_stmt->bindParam(':group_cd', intval($group_cd));
      $examples_stmt->execute();
      $example_records = $examples_stmt->fetchALL(PDO::FETCH_ASSOC);

      if (!empty($example_records)) {
        $examples = array_map(function ($record) {
          return new Example($record);
        }, $example_records);

        $group_name = $example_records[0]['group_name'];
      } else {
        $group_name_stmt = $db->prepare("SELECT group_name FROM t_example_group WHERE group_cd = :group_cd");
        $group_name_stmt->bindParam(':group_cd', $group_cd);
        $group_name_stmt->execute();
        $group_name_row = $group_name_stmt->fetch();
        $group_name = $group_name_row['group_name'];
      }

      $languages = Language::getLanguage()->toArray();
      if(empty($examples)) {
        $json = json_encode([
            'items' => [],
            'languages' => $languages
        ]);
      } else {

        $items = array_map(function($i, $idx) {
            return [
              'example' => $i->toArray(),
                'insert_flag' => false,
                'update_flag' => false,
                'row_num' => $idx
            ];
          }, $examples, range(1, count($examples)));

        $json = json_encode([
          'items' => $items,
          'group_cd' => $group_cd,
          'languages' => $languages]);
      }

      // group data
      $group_data = array_map(function($i) {
        return new ExampleGroup($i);
      }, ExampleGroupMapper::fetchParents($group_cd));

      if (empty($group_data)) {
        $group_data_json = json_encode(['group_names' => []]);
      } else {
        $group_data_json = json_encode(['group_names' =>
          array_map(function($i) {
            return [
              'group_name' => $i->group_name
            ];
          }, $group_data),
        ]);
      }

    } else {
      $disp_group = json_encode([
        'items' => ExampleGroupMapper::fetchLeaf()->toArray(),
        'seen' => true
      ]);

    }

    ?>
  </head>
  <body>
    <?php echo $twig->load('navbar.html.twig')->render(); ?>
    <main>
      <div class="container">
        <div class="row">
          <script id="json-vue" data-json="<?= h($json) ?>"></script>
          <script id="group-vue" data-json="<?= ($group_cd == '') ? '' : h("{ \"group_cd\": ${group_cd}, \"group_name\": \"${group_name}\" }") ?>"></script>
          <script id="disp-group-vue" data-json="<?= ($disp_group == '') ? '{&quot;items&quot;: null, &quot;seen&quot;: false}' : h($disp_group) ?>"></script>
          <script id="group-names-vue" data-json="<?= h($group_data_json) ?>"></script>
          <section id="disp-group" v-cloak>
            <table v-show="seen">
              <thead>
                <tr>
                  <th v-for="key in gridColumns">
                    {{ key }}
                  </th>
                </tr>
              </thead>
              <tr v-for="item in items">
                <td><a v-bind:href="'register.php?group_cd=' + item.group_cd">{{ item.group_name }}</a></td>
              </tr>
            </table>
          </section>
          <form name="save-form" action="register.php?group_cd=<?= $group_cd; ?>" method="post">
            <section id="app" v-cloak>
              <h2><a href="register.php?group_cd=<?= $group_cd; ?>">{{ group_name }}</a></h2>
              <span v-for="name in group_names">
                <span>[{{ name.group_name }}] </span>
              </span>
              <table>
                <thead>
                  <tr>
                    <th v-for="key in gridColumns">
                      {{ key }}
                    </th>
                  </tr>
                </thead>
                <tr v-for="(item, index) in items"
                    :key="item.row_num">
                  <td>
                    <span v-if="item.insert_flag">
                      <select :name="'items[' + index + '][example][language]'" v-model="item.example.language" required>
                        <option v-for="language in languages" :value="language.language">
                          {{ language.language }}
                        </option>
                      </select>
                    </span>
                    <span v-else>
                      {{ item.example.language }}
                      <input :name="'items[' + index + '][example][language]'" type="hidden" v-model="item.example.language"/>
                    </span>
                  </td>
                  <td>
                    <autosize-textarea :name="'items[' + index + '][example][example]'" v-model="item.example.example" required>
                      {{ item.example.example }}
                    </autosize-textarea>
                  </td>
                  <td>
                    <input :name="'items[' + index + '][example][example_id]'" type="hidden" v-model.number="item.example.example_id"/>
                    <input :name="'items[' + index + '][group_cd]'" type="hidden" v-model.number="item.group_cd"/>
                    <input :name="'items[' + index + '][insert_flag]'" type="hidden" v-model="item.insert_flag"/>
                    <button class="btn" type="button" v-on:click="remove(index, item.example.example_id)">削除</button>
                  </td>
                </tr>
              </table>
              <div style="display:none;">
                <span v-for="item in delete_target">
                  <input :name="'delete_target[]'" :key="item.example_id" type="number" v-model.number="item.example_id"/>
                </span>
              </div>
              <button class="btn" type="button" v-on:click="add">追加</button>
              <button class="btn" type="submit">保存</button>
            </section>
          </form>
          <script src="https://cdnjs.cloudflare.com/ajax/libs/autosize.js/3.0.16/autosize.min.js"></script>
          <script src="assets/js/dispGroup.vue"></script>
          <script src="assets/js/register.vue"></script>
        </div>
      </div>
    </main>
    <?php echo $twig->load('footer.html')->render(); ?>
  </body>
</html>
