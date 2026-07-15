<?php

include("connection.php");
error_reporting(0);
session_start();
$lastInsertedTitle = $_SESSION['lastInsertedTitle'];
$qu = "SELECT FOOD_COST, TRANSPORT_COST from db1 WHERE Title = '$lastInsertedTitle'";
$result = mysqli_query($conn, $qu);
$em = $_SESSION['eml']; // Assuming $em holds the email in your session
echo $em;

if ($result) {
    $row = mysqli_fetch_assoc($result);
    $foodCost = (float)$row['FOOD_COST'];
    $transportCost = (float)$row['TRANSPORT_COST'];

    echo "Last Inserted Title: ". $lastInsertedTitle;

    if(isset($_POST['next'])){
        $mu = $_POST['mue'];
        $p = $_POST['pr'];
        $c = $_POST['ci'];
        $club = $_POST['cl'];
        $party = $_POST['pt'];
        $clth = $_POST['clt'];
        $crd = $_POST['carr'];
        $gif = $_POST['gi'];
        $f = $_POST['fl'];
        $so = $_POST['so'];
        $stm = $_POST['stamps'];
        $m = $_POST['misc'];
        $subTotal = (float)$mu + (float)$p +  (float)$c + (float)$club + (float)$party ;
        $total =  (float)$clth +  (float)$crd + (float)$gif +  (float)$f +  (float)$so +  (float)$stm ; 
        $grand = $subTotal + $total + $foodCost + $transportCost;
        echo $grand;
        $query = "INSERT INTO db3 VALUES ('$mu','$p','$c','$club','$party','$clth','$crd','$gif','$f','$so','$stm','$m','$subTotal','$total','$lastInsertedTitle','$grand','$em')";

        $data = mysqli_query($conn,$query);

        if($data){

            
           header("location:view-list.php");
        }
       else{
            echo 'failed';
       }

    }
}
?>
 <!DOCTYPE html>
<html lang="en">
<head>

    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Multikart admin is super flexible, powerful, clean &amp; modern responsive bootstrap 4 admin template with unlimited possibilities.">
    <meta name="keywords" content="admin template, Multikart admin template, dashboard template, flat admin template, responsive admin template, web app">
    <meta name="author" content="pixelstrap">
    <link rel="icon" href="../assets/images/dashboard/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="../assets/images/dashboard/favicon.png" type="image/x-icon">
    <title>Travel Journel</title>

    <!-- Google font-->
    <link href="https://fonts.googleapis.com/css?family=Work+Sans:100,200,300,400,500,600,700,800,900" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Font Awesome-->
    <link rel="stylesheet" type="text/css" href="css/fontawesome.css">

    <!-- Ico-font-->
    <link rel="stylesheet" type="text/css" href="css/icofont.css">

    <!-- Flag icon-->
    <link rel="stylesheet" type="text/css" href="css/flag-icon.css">

    <!-- Bootstrap css-->
    <link rel="stylesheet" type="text/css" href="css/bootstrap.css">
    <!--Google font-->
    <link href="https://fonts.googleapis.com/css?family=Lato:300,400,700,900" rel="stylesheet">

    <!-- Icons -->
   <link rel="stylesheet" type="text/css" href="font-awesome-4.7.0/css/font-awesome.css">
    <link rel="stylesheet" href="font-awesome-4.7.0/css/font-awesome.min.css">
    <!-- App css-->
    <link rel="stylesheet" type="text/css" href="css/admin.css">
    
    <!-- date-picker css-->
    <link rel="stylesheet" type="text/css" href="css/date-picker.css">
    
    <link rel="stylesheet" type="text/css" href="css/dropzone.css">
</head>
<body>

<!-- page-wrapper Start-->
<div class="page-wrapper">

    <!-- Page Header Start-->
         <div class="page-main-header">
        <div class="main-header-right row">
            <div class="main-header-left d-lg-none">
               
            </div>
            <div class="mobile-sidebar">
                <div class="media-body text-right switch-sm">
                    <label class="switch"><a href="#"><i id="sidebar-toggle" data-feather="align-left"></i></a></label>
                </div>
            </div>
            <div class="nav-right col">
                <ul class="nav-menus">
                    <li>
                        
                    </li>
                    <li class="onhover-dropdown">
                        <div class="media align-items-center">
                            <a href="#" id="user1">PROFILE</a>
                        </div>
                        <ul class="profile-dropdown onhover-show-div p-20">
                            <li><a href="profile.php"><i data-feather="user"></i>Profile</a></li>
                            <li><a href="#"><i data-feather="log-out"></i>LOGIN</a></li>
                        </ul>
                    </li>
                </ul>
                <div class="d-lg-none mobile-toggle pull-right"><i data-feather="more-horizontal"></i></div>
            </div>
        </div>
    </div>
   
    
    <!-- Page Header Ends -->

    <!-- Page Body Start-->
    <div class="page-body-wrapper">

        <!-- Page Sidebar Start-->
        <div class="page-sidebar">
            <div class="main-header-left d-none d-lg-block">
               
            </div>
            <div class="sidebar custom-scrollbar">
                <div class="sidebar-user text-center">
                    <div class="sidebar-user text-center">
                    
                    <h6 class="mt-3 f-14">PROFILE</h6>
                </div>
                </div>
                <ul class="sidebar-menu">
                    <li ><a href="dashboard.php" class="sidebar-header"  id="user2"><i data-feather="home"></i><span>Home</span></a></li>
                    <li><a class="sidebar-header" href="dashboard.php" id="user2"><i data-feather="edit"></i><span>New Entry</span></a></li>
                    <li><a class="sidebar-header" href="view-list.php" id="user2"><i data-feather="file-text"></i><span>View All Entries</span></a></li>
                    <li><a class="sidebar-header" href="plan-trip.php" id="user2"><i data-feather="compass"></i><span>Plan a Trip</span></a></li>
                    <li><a class="sidebar-header" href="recommendations.php" id="user2"><i data-feather="star"></i><span>For You</span></a></li>
                    <li><a class="sidebar-header" href="analytics.php" id="user2"><i data-feather="bar-chart-2"></i><span>Analytics</span></a></li>
                    <li><a class="sidebar-header" href="pc.php" id="user2"><i data-feather="file-text"></i><span>View My Entries</span></a></li>
                    <li><a class="sidebar-header" href="login.php" id="user2"><i data-feather="log-in"></i><span>LOGIN</span></a>
                    </li>
                    
                </ul>
            </div>
        </div>
        <!-- Page Sidebar Ends-->

        <div class="page-body">

            <!-- Container-fluid starts-->
            <div class="container-fluid">
                <div class="page-header">
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="page-header-left">
                                <h3 id="j1">PROFILE's Journel
                                </h3>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <ol class="breadcrumb pull-right">
                                <li class="breadcrumb-item"><a href="dashboard.php" id="user1"><i data-feather="home"></i></a></li>
                                <li class="breadcrumb-item" id="user1">Create Journel</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Container-fluid Ends-->

            <!-- Container-fluid starts-->
            <div class="container-fluid">
                <div class="card tab2-card">
                    <div class="card-header">
                        <h5>Expenses</h5>
                    </div>
                    <div class="card-body">
                        <div class="tab-content" id="myTabContent">
                            <div class="tab-pane fade active show" id="general" role="tabpanel" aria-labelledby="general-tab">
                                <form class="needs-validation" method="POST">
                                    <div class="form-group row col-xl-12">
                                            <div class="tab-pane fade active show" id="top-profile" role="tabpanel" aria-labelledby="top-profile-tab">
                                            
                                            <h5 class="f-w-600">xii) Entry Fees</h5>    
                                            <div class="table-responsive profile-table ">
                                                <table class="table table-responsive">
                                                    <tbody>
                                                    <tr>
                                                        <td>a) Museum Entry:</td>
                                                        <td  ><input class="form-control " id="validationCustom0" name="mue" type="text"></td>
                                                    </tr>
                                                        <tr>
                                                        <td>b) Park Entry:</td>
                                                        <td  ><input class="form-control " id="validationCustom0" name="pr" type="text"></td>
                                                    </tr>
                                                        <tr>
                                                        <td>c) Cinema Hall Entry:</td>
                                                        <td  ><input class="form-control " id="validationCustom0" name="ci" type="text"></td>
                                                    </tr>
                                                        <tr>
                                                        <td>d) Club Entry:</td>
                                                        <td  ><input class="form-control " id="validationCustom0" name="cl" type="text"></td>
                                                    </tr>
                                                    <tr>
                                                        <td>e) Party Entry:</td>
                                                        <td  ><input class="form-control " id="validationCustom0" name="pt" type="text"></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="f-w-600">Sub Total:</td>
                                                        <td ><input class="form-control " id="validationCustom0" name="st" type="text"></td>
                                                    </tr>
                                                    </tbody>
                                                </table>
                                                
                                            </div>
                                                
                                            <h5 class="f-w-600">xiii) Shopping Expenses</h5>    
                                            <div class="table-responsive profile-table">
                                                <table class="table table-responsive">
                                                    <tbody>
                                                    <tr>
                                                        <td>a) Clothes:</td>
                                                        <td><input class="form-control " id="validationCustom0" name="clt" type="text"></td>
                                                    </tr>
                                                        <tr>
                                                        <td>b) Cards:</td>
                                                        <td ><input class="form-control " id="validationCustom0" name="carr" type="text"></td>
                                                    </tr>
                                                        <tr>
                                                        <td>c) Gift Items:</td>
                                                        <td><input class="form-control " id="validationCustom0" name="gi" type="text"></td>
                                                    </tr>
                                                        <tr>
                                                        <td>d) Flight Fare:</td>
                                                        <td><input class="form-control " id="validationCustom0" name="fl" type="text"></td>
                                                    </tr>
                                                    <tr>
                                                        <td>e) Souvenirs:</td>
                                                        <td><input class="form-control " id="validationCustom0" name="so" type="text"></td>
                                                    </tr>
                                                        <tr>
                                                        <td>f) Stamps:</td>
                                                        <td><input class="form-control " id="validationCustom0" name="stamps" type="text"></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="f-w-600">Sub Total:</td>
                                                        <td><input class="form-control " id="validationCustom0" name="ttl" type="text"></td>
                                                    </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                            
                                            <h5 class="f-w-600">xiv) Extra</h5>    
                                            <div class="table-responsive profile-table">
                                                <table class="table table-responsive">
                                                    <tbody>
                                                    <tr>
                                                        <td>a) Misc. Cost:</td>
                                                        <td><input class="form-control " id="validationCustom0" name = "misc" type="text"></td>
                                                    </tr>
                                                    </tbody>
                                                </table>
                                            </div> 
                                                     
                                                
                                                
                                           </div>
                                        
                                    </div>
                                   
                                
                                <table class="table cart-table table-responsive-md">
                                    <tfoot>
                                        <tr>
                                            <td><h2>Grand Total:</h2></td>
                                            <td><input class="form-control " id="validationCustom0" name="gt" type="text">
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                                <div class="typo-content product-pagination ">
                                    <ul class="pagination">
                                        <li class="page-item"><a class="page-link" href="dashboard2.php" aria-label="Previous"><span aria-hidden="true"><i class="fa fa-chevron-left" aria-hidden="true"></i></span> <span class="sr-only">Previous</span></a>
                                        </li>
                                        <li class="page-item active"><a class="page-link" href="dashboard2.php">Previous</a></li>
                                        <li class="page-item"><a class="page-link" href="#">Next</a></li>
                                        <li class="page-item"><a class="page-link" href="#" aria-label="Next"><span aria-hidden="true"><i class="fa fa-chevron-right" aria-hidden="true"></i></span> <span class="sr-only">Next</span></a>
                                        </li>
                                    </ul>
                                </div>
                                                
                            </div>
                        </div>
                        <div class="pull-right">
                            <input type="Submit" name = "next" value="SAVE" class="btn btn-primary" onclick="location.href='view-list.php' ;">
                        </div>
                    </div>
                    
                    </form>
                </div>
            </div>
            <!-- Container-fluid Ends-->

        </div>
        <!-- footer start-->
        <footer class="footer">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-md-6 footer-copyright">
                        <p class="mb-0">Copyright 2023 © Travel All rights reserved.</p>
                    </div>
                </div>
            </div>
        </footer>
        <!-- footer end-->
    </div>
    </div>
<!-- latest jquery-->
<script src="js/jquery-3.3.1.min.js"></script>

<!-- Bootstrap js-->
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.js"></script>

<!-- feather icon js-->
<script src="js/feather.min.js"></script>
<script src="js/feather-icon.js"></script>

<!-- Sidebar jquery-->
<script src="js/sidebar-menu.js"></script>


<!--Customizer admin-->
<script src="js/admin-customizer.js"></script>

<!-- lazyload js-->
<script src="js/lazysizes.min.js"></script>

<!--right sidebar js-->
<script src="js/chat-menu.js"></script>
    
    
<!-- dropzone js-->
<script src="js/dropzone-script.js"></script>
<script src="js/dropzone.js"></script>    
    
    

<!--Datepicker jquery
    
 <script src="js/datepicker.custom.js"></script>
<script src="js/datepicker.en.js"></script>
<script src="js/datepicker.js"></script>-->
    
<!--script admin-->
<script src="js/admin-script.js"></script>

</body>
</html>