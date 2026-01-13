# Page Analyzer

[![Actions Status](https://github.com/ElenaManukyan/php-project-9/actions/workflows/hexlet-check.yml/badge.svg)](https://github.com/ElenaManukyan/php-project-9/actions)
[![PHP-Linter](https://github.com/ElenaManukyan/php-project-9/actions/workflows/php.yml/badge.svg)](https://github.com/ElenaManukyan/php-project-9/actions/workflows/php.yml)

**Page Analyzer** is a full-fledged web application built with PHP and the Slim framework that analyzes specified web pages for SEO suitability. The service checks page availability and extracts critical SEO tags.

## Demo:
(Demo)[https://php-project-9-y79n.onrender.com]

## 📋 Project Description

This application allows you to:
* **Add new sites** for monitoring via a user-friendly form on the main page.
* **View a list of all added resources**, including the results of their latest checks (status code, date).
* **Run detailed SEO checks** for a specific page.
* **Maintain a check history**, storing:
    * HTTP response code.
    * Page Title (from the `<title>` tag).
    * Main heading (`<h1>`).
    * Meta description (`description`).

## 🛠 Tech Stack

* **Backend:** PHP (Slim Framework).
* **Database:** PostgreSQL (using PDO library for queries).
* **Frontend:** Bootstrap for responsive design.
* **Infrastructure:** Makefile for task automation, deployed via Render.com.

## 💻 System Requirements

* **PHP:** >= 8.1
* **Composer:** For dependency management.
* **PostgreSQL:** For data storage.
* **Make:** To execute build and run commands.

---

## 🚀 Installation & Setup

### 1. Clone the Repository
```bash
git clone https://github.com/ElenaManukyan/php-project-9.git
cd php-project-9
```
### 2. Install Dependencies
Use Composer via the provided Makefile:
```bash
make install
```
### 3. Database Setup
The application requires **PostgreSQL**. Follow these steps to prepare your local environment:
1. **Create the database:**
   ```bash
   createdb page_analyzer
   ```
   or
   
   1.1. Enter the standard PostgreSQL console
   ```bash
   psql postgres
   ```
   1.2. Execute the query inside the console:
   ```bash
   CREATE DATABASE page_analyzer
   ```
   1.3. Exit the console
   ```bash
   \q
   ```
3. Initialize the table structure using the provided SQL file:
   ```bash
   psql -d page_analyzer -f database.sql
   ```
4. Set up your environment variables: Create a ```.env``` file in the root directory and add your connection string:
   ```bash
   DATABASE_URL=postgresql://<your_login>:<your_password>@localhost:<your_port>/<your_name_db>
   ```
### 4. Launch the Application
Start the built-in PHP server:
```bash
make start
```
By default, the application will be available at: ```http://localhost:8000```.

## 🛠 Available Commands (Makefile)

* ```make start``` - Starts the web server on port 8000.
* ```make install``` - Installs all project dependencies via Composer.
* ```make lint``` - Runs the PHP linter (PHP_CodeSniffer) following the PSR12 standard.
* ```make stop``` - Stops the server running on the specified port.

## 📸 Interface Preview
The application features an intuitive interface divided into three main sections:
1. **Home Page:** A form to input a URL and add a site to the system.
   <img width="1918" height="865" alt="image" src="https://github.com/user-attachments/assets/3af51943-70b7-4e41-a2ec-a25f6962520c" />
2. **Sites List:** A table showing all resources and the status of their most recent check.
   <img width="1919" height="861" alt="image" src="https://github.com/user-attachments/assets/1c100446-ca40-4b1d-931a-fb562abcbd69" />
3. **Analysis Page:** Detailed historical data for every check performed on a specific URL.
   <img width="1897" height="864" alt="image" src="https://github.com/user-attachments/assets/ae60917f-f840-4562-a768-0ee72fec9e36" />


