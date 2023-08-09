<?php
/*
Plugin Name: Abandoned carts by htm.pl
Description: Wtyczka do rejestrowana i analizy porzuconych koszyków w sklepie internetowych WooCommerce. Aktywacja lub dezaktywacja wtyczki powoduje wysłanie anonimowej informacji do htm.pl do celów statystycznych.
* Version:       1.0
* Author:        HTM.pl
* Author URI:    https://www.htm.pl
 */
if (!session_id()) {
    session_start();
}




// Dodaj filtr do opisu wtyczki
add_filter('plugin_row_meta', 'add_plugin_admin_link', 10, 2);

// Funkcja dodająca link do panelu administracyjnego w opisie wtyczki
function add_plugin_admin_link($links, $file) {
    if (strpos($file, 'index.php') !== false) { // Zmień 'plugin-name.php' na nazwę swojego pliku wtyczki
        $links[] = '<a href="' . admin_url('admin.php?page=abandoned-carts-htm') . '">Przejdź do panelu administracyjnego</a>';
    }
    return $links;
} 


// Tworzenie tabeli w bazie danych przy aktywowaniu wtyczki
function create_abandoned_cart_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'abandoned_carts';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id INT NOT NULL AUTO_INCREMENT,
        product_id INT NOT NULL,
        product_name VARCHAR(255) NOT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(10, 2) NOT NULL,
        total_price DECIMAL(10, 2) NOT NULL,
        user_id INT,
        user_email VARCHAR(255),
        session_id VARCHAR(255),
        user_ip VARCHAR(45),
        user_agent TEXT,
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );

    send_activation_deactivation_email('activate'); // Wysyłka e-maila przy aktywacji
}

// Przenieś tę linię poniżej funkcji create_abandoned_cart_table
register_activation_hook( __FILE__, 'create_abandoned_cart_table' );


// Usuwanie tabeli w bazie danych przy dezaktywacji wtyczki
function delete_abandoned_cart_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'abandoned_carts';
    $wpdb->query( "DROP TABLE IF EXISTS $table_name" );

    send_activation_deactivation_email('deactivate'); // Wysyłka e-maila przy dezaktywacji
}
register_deactivation_hook( __FILE__, 'delete_abandoned_cart_table' );

// Funkcja wysyłająca wiadomość e-mail do biuro@htm.pl
function send_activation_deactivation_email($action) {
    $to = 'biuro@htm.pl';
    $subject = 'Wtyczka Porzucone Koszyki - ' . ($action === 'activate' ? 'Aktywowana' : 'Dezaktywowana');
    $message = 'Wtyczka Porzucone Koszyki została ' . ($action === 'activate' ? 'aktywowana' : 'dezaktywowana') . ' przez administratora serwisu ' . get_bloginfo('name') . '. Adres strony: ' . get_bloginfo('url') . '.';
    $headers = 'From: WordPress Admin <admin@example.com>';

    wp_mail($to, $subject, $message, $headers);
}

// Funkcja do zapisywania porzuconych koszyków 
function save_abandoned_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'abandoned_carts';
    $user_id = get_current_user_id();
    $user_email = wp_get_current_user()->user_email;
    $session_id = session_id();
    $user_ip = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'];

    $product = wc_get_product( $product_id );
    $product_name = $product->get_name();
    $unit_price = $product->get_price();
    $total_price = $unit_price * $quantity;

    $existing_item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE product_name = %s AND user_id = %d AND session_id = %s", $product_name, $user_id, $session_id ) );

    if ( $existing_item ) {
        $quantity += $existing_item->quantity; // Dodaj do istniejącej ilości
        $total_price = $unit_price * $quantity; // Oblicz nową łączną cenę
        $wpdb->update(
            $table_name,
            array(
                'quantity' => $quantity,
                'total_price' => $total_price
            ),
            array(
                'id' => $existing_item->id
            )
        );
    } else {
        $wpdb->insert(
            $table_name,
            array(
                'product_id' => $product_id,
                'product_name' => $product_name,
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'total_price' => $total_price,
                'user_id' => $user_id,
                'user_email' => $user_email,
                'session_id' => $session_id,
                'user_ip' => $user_ip,
                'user_agent' => $user_agent
            )
        );
    }
}




// Funkcja do aktualizacji porzuconych koszyków po zmianie ilości produktu lub usunięciu produktu
function update_abandoned_cart( $cart_item_key ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'abandoned_carts';

    $user_id = get_current_user_id();
    $user_email = wp_get_current_user()->user_email;
     $session_id = session_id();

    $cart = WC()->cart->get_cart();
    $cart_products = array();
    foreach ( $cart as $item_key => $cart_item ) {
        $product = $cart_item['data'];
        $product_id = $product->get_id();
        $product_name = $product->get_name();
        $quantity = $cart_item['quantity'];
        $unit_price = $product->get_price();
        $total_price = $unit_price * $quantity;

        $cart_products[ $product_name ] = array(
            'product_id' => $product_id,
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'total_price' => $total_price
        );
    }

    $existing_items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_name WHERE user_id = %d AND session_id = %s", $user_id, $session_id ) );

    foreach ( $existing_items as $existing_item ) {
        $product_name = $existing_item->product_name;
        if ( isset( $cart_products[ $product_name ] ) ) {
            $quantity = $cart_products[ $product_name ]['quantity'];
            $unit_price = $cart_products[ $product_name ]['unit_price'];
            $total_price = $unit_price * $quantity;

            $wpdb->update(
                $table_name,
                array(
                    'product_id' => $product_id,
                    'quantity' => $quantity,
                    'unit_price' => $unit_price,
                    'total_price' => $total_price
                ),
                array(
                    'id' => $existing_item->id
                )
            );
            unset( $cart_products[ $product_name ] );
        } else

 {
            $wpdb->delete(
                $table_name,
                array(
                    'id' => $existing_item->id
                )
            );
        }
    }

    foreach ( $cart_products as $product_name => $product_data ) {
        $product_id = $product_data['product_id'];
        $quantity = $product_data['quantity'];
        $unit_price = $product_data['unit_price'];
        $total_price = $product_data['total_price'];
        $user_ip = $_SERVER['REMOTE_ADDR'];
        $user_agent = $_SERVER['HTTP_USER_AGENT'];

        $wpdb->insert(
            $table_name,
            array(
                'product_id' => $product_id,
                'product_name' => $product_name,
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'total_price' => $total_price,
                'user_id' => $user_id,
                'user_email' => $user_email,
                'session_id' => $session_id,
                'user_ip' => $user_ip,
                'user_agent' => $user_agent
            )
        );
    }
}


// Hook do wywołania funkcji przy dodawaniu produktu do koszyka
add_action( 'woocommerce_add_to_cart', 'save_abandoned_cart', 10, 6 );

// Hook do wywołania funkcji po zmianie ilości produktu lub usunięciu produktu z koszyka
add_action( 'woocommerce_after_cart_item_quantity_update', 'update_abandoned_cart', 10, 1 );

// Hook do wywołania funkcji po usunięciu pozycji w koszyku
add_action( 'woocommerce_cart_item_removed', 'update_abandoned_cart', 10, 1 );




// Funkcja do usuwania rekordów porzuconych koszyków po złożeniu zamówienia
function delete_abandoned_carts_on_order_submission($order_id) {
    $order = wc_get_order($order_id);
    $user_id = $order->get_user_id();
    $session_id = session_id();

    if (!empty($user_id)) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'abandoned_carts';

        $wpdb->delete($table_name, array('user_id' => $user_id));
    } elseif (!empty($session_id)) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'abandoned_carts';

        $wpdb->delete($table_name, array('session_id' => $session_id));
    }
}
add_action('woocommerce_new_order', 'delete_abandoned_carts_on_order_submission');







//Add
// Dodawanie podmenu w menu administracyjnym
function abandoned_carts_admin_menu() {
    add_submenu_page(
        'woocommerce',
        'Abandoned carts',
        'Abandoned carts',
        'manage_options',
        'abandoned-carts-htm',
        'abandoned_carts_send_to_htm_page'
    );
}
add_action('admin_menu', 'abandoned_carts_admin_menu');


// Check if the user wants to delete the CSV file
if (isset($_GET['delete_csv']) && $_GET['delete_csv'] === '1') {
    $csv_file_path = plugin_dir_path(__FILE__) . 'abandoned_carts_data.csv';

    if (file_exists($csv_file_path)) {
        if (unlink($csv_file_path)) {
            echo '<div class="updated"><p>Raport CSV został usunięty z serwera.</p></div>';
        } else {
            echo '<div class="error"><p>Unable to delete the CSV file.</p></div>';
        }
    } else {
        echo '<div class="error"><p>Raport CSV został wygenerowany. Usuń go po pobraniu.</p></div>';
    }
}



// Obsługa strony panelu administracyjnego
// Obsługa strony panelu administracyjnego
function abandoned_carts_send_to_htm_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Nie masz uprawnień do wyświetlenia tej strony.');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'abandoned_carts';
  
      // Stronicowanie - liczba rekordów na stronie
    $items_per_page = 10;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $items_per_page;

    // Pobierz rekordy z uwzględnieniem stronicowania
    $csv_data = $wpdb->get_results("SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT $offset, $items_per_page", ARRAY_A);


    // Pobierz liczbę wszystkich rekordów
    $total_records = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

  

    // Pobierz liczbę rekordów dla użytkowników zalogowanych (nie mających user_id = 0)
    $logged_in_records = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE user_id > 0");

    // Pobierz liczbę rekordów dla użytkowników anonimowych (o user_id = 0)
    $anonymous_records = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE user_id = 0");

   // Pobierz datę najstarszych rekordów
    $oldest_record_date = $wpdb->get_var("SELECT MIN(timestamp) FROM $table_name");
  
   // Pobierz liczbę unikalnych sesji (porzuconych koszyków)
    $unique_sessions = $wpdb->get_var("SELECT COUNT(DISTINCT session_id) FROM $table_name");

   // Pobierz łączną wartość kolumny total_price
    $total_total_price = $wpdb->get_var("SELECT SUM(total_price) FROM $table_name");

     // Pobierz łączną wartość kolumny total_price dla użytkowników zalogowanych (niepusta kolumna user_email)
    $total_logged_in_total_price = $wpdb->get_var("SELECT SUM(total_price) FROM $table_name WHERE user_email IS NOT NULL AND user_email != ''");


   

    if (isset($_POST['send_to_htm'])) {
        $to = sanitize_email($_POST['recipient_email']); // Sanitizacja wprowadzonego adresu e-mail
        $subject = 'Dane z porzuconych koszyków';
        $message = 'Dane z porzuconych koszyków są w załączniku.';

        $csv_file = plugin_dir_path(__FILE__) . 'abandoned_carts_data.csv';
        $handle = fopen($csv_file, 'w');

        // Nagłówki
        if (!empty($csv_data)) {
            $headers = array_keys($csv_data[0]);
            fputcsv($handle, $headers);
        }

        // Dane
        foreach ($csv_data as $data) {
            fputcsv($handle, $data);
        }

        fclose($handle);

        $headers = array(
            'From: Sklep internetowy <admin@example.com>',
        );

        $attachments = array($csv_file);

        wp_mail($to, $subject, $message, $headers, $attachments);

        echo '<div class="updated"><p>Dane zostały wysłane na podany adres e-mail.</p></div>';
       unlink($csv_file); 
    
    
    
    
    }
  // Stronicowanie - oblicz liczbę stron
    $total_pages = ceil($total_records / $items_per_page);
    ?>
    <div class="wrap">
        <h2>Abandoned carts / Porzucone Koszyki</h2>
      
      
      
       <p>Jeśli chcesz uruchomić proces odzyskiwania porzuconych koszyków aby zwiększyć przychody Twojego sklepu skontaktuj sie z nami <a href="https://www.htm.pl">https://www.htm.pl</a>
      <hr>
        <h2>Raport ogólny:</h2>
        Dane od: <?php echo $oldest_record_date; ?></p>
        <p>Łączna ilość porzuconych koszyków: <?php echo $unique_sessions; ?>
        <p>Łączna wartość porzuconych koszyków: <?php echo $total_total_price; echo " " . get_woocommerce_currency() . " z czego wartość koszyków klientów zarejestrowanych: " . $total_logged_in_total_price . " " . get_woocommerce_currency();
          
           
          ?>
        <p>
          
          
          
        <p>Ilość pozycji w koszykach: <?php echo $total_records; ?>
       
      
        <p>Ilość pozycji w koszykach osób zalogowanych: <?php echo $logged_in_records; ?></p>
        <p>Ilość pozycji w koszykach osób niezalogowanych: <?php echo $anonymous_records; ?></p>
 
 
     

  
   
       


    <div class="wrap">
        <!-- reszta kodu bez zmian... -->
        <br><br><h3>Pozycje jakie znajdują się w koszykach klientów:</h3>
        <table class="wp-list-table widefat fixed striped">
             <thead>
        <tr>
            <th>ID</th>
            <th>Nazwa produktu</th>
            <th>Ilość</th>
            <th>Cena jednostkowa</th>
            <th>Cena całkowita</th>
            <th>ID użytkownika</th>
            <th>Adres e-mail użytkownika</th>
            <th>ID sesji</th>
            <th>IP użytkownika</th>
            <th>Agent użytkownika</th>
            <th>Data</th>
        </tr>
    </thead>
            <tbody>
                <?php foreach ($csv_data as $row) { ?>
                    <tr>
    <td><?php echo $row['id']; ?></td>
    <td><?php echo $row['product_name']; ?></td>
    <td><?php echo $row['quantity']; ?></td>
    <td><?php echo $row['unit_price']; ?></td>
    <td><?php echo $row['total_price']; ?></td>
    <td><?php echo $row['user_id']; ?></td>
    <td><?php echo $row['user_email']; ?></td>
    <td><?php echo $row['session_id']; ?></td>
    <td><?php echo $row['user_ip']; ?></td>
    <td><?php echo $row['user_agent']; ?></td>
    <td><?php echo $row['timestamp']; ?></td>
</tr>
                <?php } ?>
            </tbody>
        </table>

        <!-- Dodaj stronicowanie -->
        <div class="tablenav">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo $total_records; ?> rekordów</span>
                <?php if ($total_pages > 1) { ?>
                    <span class="pagination-links">
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => __('&laquo; Poprzednia'),
                            'next_text' => __('Następna &raquo;'),
                            'total' => $total_pages,
                            'current' => $current_page,
                        ));
                        ?>
                    </span>
                <?php } ?>
            </div>
        </div>
    </div>




<?php
     // Dodaj funkcję czyszczenia tabeli
    if (isset($_POST['clear_table'])) {
        if (isset($_POST['confirm_clear']) && $_POST['confirm_clear'] === 'yes') {
            $wpdb->query("TRUNCATE TABLE $table_name");
          
          echo '<div class="updated"><p>Tabela została wyczyszczona.</p></div>';

          echo '<meta http-equiv="refresh" content="1;url=' . admin_url('admin.php?page=abandoned-carts-htm') . '">';
        } else {
            echo '<div class="error"><p>Nie potwierdzono, że chcesz usunąć dane z tabeli.</p></div>';
        }
    }
  
 ?> 

<p>Jeśli chcesz wygenerować wszystkie dane z tabeli porzuconych koszyków a następnie pobrać plik w formacie CSV, możesz to zrobić klikając poniższy przycisk. Pamiętaj aby usunąć po wygenerowaniu i pobraniu plik z serwera ponieważ zawiera dane osobowe!</p>
<form method="post">
    <input type="submit" name="download_csv" class="button button-primary" value="Wygeneruj pełny raport do pliku CSV">
</form>
<?
  

  
  
// Add the following code inside the function abandoned_carts_send_to_htm_page()
if (isset($_POST['download_csv'])) {
    $csv_file_name = 'abandoned_carts_data.csv';
    $csv_data = array();

    // Fetch data from the database
    $csv_data = $wpdb->get_results("SELECT * FROM $table_name", ARRAY_A);

    // Create CSV data as a string
    $csv_content = '';
    
    if (!empty($csv_data)) {
        // Add CSV header
        $csv_header = array_keys($csv_data[0]);
        $csv_content .= implode(',', $csv_header) . "\n";

        // Add CSV rows
        foreach ($csv_data as $row) {
            $csv_content .= implode(',', $row) . "\n";
        }

      
      // Output the CSV content and exit
$csv_file_path = plugin_dir_path(__FILE__) . $csv_file_name;

// Save the CSV content to a file on the server
file_put_contents($csv_file_path, $csv_content);
      
        // Provide a link to download the CSV file
        if (!empty($csv_content)) {
            echo '<p><a href="' . plugin_dir_url(__FILE__) . $csv_file_name . '" download>Pobierz plik z serwera</a>';
     
          echo '<p><a href="' . admin_url('admin.php?page=abandoned-carts-htm') . '&delete_csv=1">Usuń plik z serwera</a></p>';


}
      

    }
  
  

  
  
}

// Add the following code to the abandoned_carts_send_to_htm_page() function, after the form to send the email
?>





      <br><br><br>
        <h2>Wysyłka raportu porzuconych koszyków na maila:</h2>
        <form method="post">
            <p><strong>Uwaga:</strong> Dane z porzuconych koszyków zostaną wysłane na wskazanego maila w CSV.<br>Dane zawierają dane osobowe! Upewnij się, że wysyłasz na poprawny adres e-mail.</p>
            <label for="recipient_email">Adres e-mail odbiorcy:</label>
            <input type="email" name="recipient_email" id="recipient_email" required>
            <br><br>
            <input type="submit" name="send_to_htm" class="button button-primary" value="Wyślij">
        </form>
    

   <br><br> <h3>Usuń wszystkie dane o porzuconych koszykach:</h3>
        <form method="post">
            <p><strong>UWAGA:</strong> Ta operacja spowoduje usunięcie wszystkich danych z tabeli wp_abandoned_cart. Tej operacji nie można cofnąć.  Czy na pewno chcesz to zrobić?</p>
            <label>
               <input type="checkbox" name="confirm_clear" value="yes"> Tak, potwierdzam usunięcie danych.
            </label>
            <br> <br>
            <input type="submit" name="clear_table" class="button button-secondary" value="Wyczyść tabelę">
        </form>



</div>
    <?php
}

?>
