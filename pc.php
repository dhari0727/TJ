<?php
include("connection.php");
session_start();
$em = $_SESSION['eml']; // Assuming $em holds the email in your session
echo $em;

// Fetch data from the "signup" table (prepared statements)
$stmtU = mysqli_prepare($conn, "SELECT * FROM signup WHERE eml = ?");
mysqli_stmt_bind_param($stmtU, 's', $em);
mysqli_stmt_execute($stmtU);
$dat = mysqli_stmt_get_result($stmtU);

$stmtD = mysqli_prepare($conn, "SELECT * FROM db WHERE eml = ?");
mysqli_stmt_bind_param($stmtD, 's', $em);
mysqli_stmt_execute($stmtD);
$da = mysqli_stmt_get_result($stmtD);

$rr = mysqli_num_rows($dat);

// Check if there are any rows in the "db" table
$ree = mysqli_num_rows($da);
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
    
    <link rel="stylesheet" type="text/css" href="css/jsgrid.css">
</head>
<body>

<!-- page-wrapper Start-->
<div class="page-wrapper">

    <!-- Page Header Start-->
         <div class="page-main-header">
        <div class="main-header-right row">
            <div class="main-header-left d-lg-none">
                <li><a class="sidebar-header" href="pc.php" id="user2"><i data-feather="file-text"></i><span></span></a></li>
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
                <li><a class="sidebar-header" href="pc.php" id="user2"><i data-feather="file-text"></i><span></span></a></li>
            </div>
            <div class="sidebar custom-scrollbar">
                <div class="sidebar-user text-center">
                    
                    <h6 class="mt-3 f-14">PROFILE</h6>
                </div>
                <ul class="sidebar-menu">
                    <li ><a href="dashboard.php" class="sidebar-header"  id="user2"><i data-feather="home"></i><span>Home</span></a></li>
                    <li><a class="sidebar-header" href="dashboard.php" id="user2"><i data-feather="edit"></i><span>New Entry</span></a></li>
                    <li><a class="sidebar-header" href="view-list.php" id="user2"><i data-feather="file-text"></i><span>View All Entries</span></a></li>
                    <li><a class="sidebar-header" href="plan-trip.php" id="user2"><i data-feather="compass"></i><span>Plan a Trip</span></a></li>
                    <li><a class="sidebar-header" href="recommendations.php" id="user2"><i data-feather="star"></i><span>For You</span></a></li>
                    <li><a class="sidebar-header" href="analytics.php" id="user2"><i data-feather="bar-chart-2"></i><span>Analytics</span></a></li>
                    <li><a class="sidebar-header" href="view-list.php" id="user2"><i data-feather="file-text"></i><span>View My Entries</span></a></li>
                    <li><a class="sidebar-header" href="login.php" id="user2"><i data-feather="log-in"></i><span>LOGOUT</span></a>
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
                                <li class="breadcrumb-item" id="user1">View Entries</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Container-fluid Ends-->
            <!-- Container-fluid starts-->
                        <div class="container-fluid">
                <div class="row">
                    <div class="col-sm-12">
                        <div class="card">
                            <div class="card-header">
                                <h5>All Entries</h5>
                            </div>
                            <div class="card-body">
                                <div class="btn-popup pull-right">
                                    <a href="dashboard.php" class="btn btn-secondary">Create Entry</a>
                                </div>
                                <div id="batchDelete" class="category-table order-table jsgrid" style="position: relative; height: auto; width: 100%;">
                                    <div class="jsgrid-grid-header">
                                      <table class="jsgrid-table">
                                        <tr class="jsgrid-header-row">
                                           
                                            <th class="jsgrid-header-cell" style="width: 100px;">Entry Name</th>
                                            <th class="jsgrid-header-cell jsgrid-align-right" style="width: 100px;">Created On</th>
                                            <th class="jsgrid-header-cell jsgrid-align-right" style="width: 100px;">Operation</th>
                                            
                                        </tr>
                                        
                                     </div>
                                    <div class="jsgrid-grid-body">
                                        
                                            <tbody>
                                            <form method="POST">
                                                <?php
                                                while($res2 = mysqli_fetch_assoc($da)){
                                                    echo "<center><tr class='jsgrid-header-row'> 
                                                    <td class='jsgrid-cell jsgrid-align-center' style='width: 100px;'> ". $res2['Title'] ."</td>
                                                     <td class='jsgrid-cell jsgrid-align-center' style='width: 100px;'> ". $res2['cd'] ."</td>

                                                    <td><a href='display.php?title=" . urlencode($res2['Title']) . "&date=" . urlencode($res2['cd']) . "&email=" . urlencode($res2['eml']) . "' class='btn btn-primary'>View</a>
                                                    <a href='update.php?title=" . urlencode($res2['Title']) . "&date=" . urlencode($res2['cd']) . "&email=" . urlencode($res2['eml']) . "' class='btn btn-primary'>Edit</a>
                                                    <a href='delete.php?title=" . urlencode($res2['Title']) . "&date=" . urlencode($res2['cd']) . "&email=" . urlencode($res2['eml']) . "' class='btn btn-primary'>Delete</a></td>

                                                </tr> ";
                                                }
                                                ?>
                                

                                            </form>
                                                    </tbody>
                                                </div>
                                            </table>
                                        </div>
                                    </div>

                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
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
    
<!-- Jsgrid js-->
<script src="js/jsgrid.min.js"></script>
<script src="js/griddata-page-list.js"></script>
<script src="js/jsgrid-page-list.js"></script>    

<!--Datepicker jquery
    
 <script src="js/datepicker.custom.js"></script>
<script src="js/datepicker.en.js"></script>
<script src="js/datepicker.js"></script>-->
    
<!--script admin-->
<script src="js/admin-script.js"></script>

</body>
</html>
