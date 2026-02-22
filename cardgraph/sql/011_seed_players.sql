-- Card Graph - Seed Players for Baseball Card Auction Parser
-- Migration 011: MLB Players, Legends, and Nicknames
-- Generated: 2026-02-21
-- Sources: MLB.com, ESPN, FanGraphs, CBS Sports projected 2026 rosters

-- ============================================================
-- ACTIVE MLB PLAYERS BY TEAM (2025-2026 Rosters)
-- ============================================================

-- ============================================================
-- Arizona Diamondbacks
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Ketel', 'Marte', '2B'),
('Corbin', 'Carroll', 'RF'),
('Gabriel', 'Moreno', 'C'),
('Nolan', 'Arenado', '3B'),
('Christian', 'Walker', '1B'),
('Lourdes', 'Gurriel Jr', 'LF'),
('Alek', 'Thomas', 'CF'),
('Geraldo', 'Perdomo', 'SS'),
('Joc', 'Pederson', 'DH'),
('Zac', 'Gallen', 'SP'),
('Merrill', 'Kelly', 'SP'),
('Corbin', 'Burnes', 'SP'),
('Brandon', 'Pfaadt', 'SP'),
('Jordan', 'Montgomery', 'SP'),
('Paul', 'Sewald', 'RP'),
('A.J.', 'Puk', 'RP'),
('Kevin', 'Ginkel', 'RP');

-- ============================================================
-- Atlanta Braves
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Ronald', 'Acuna Jr', 'RF'),
('Ozzie', 'Albies', '2B'),
('Austin', 'Riley', '3B'),
('Matt', 'Olson', '1B'),
('Michael', 'Harris II', 'CF'),
('Marcell', 'Ozuna', 'DH'),
('Orlando', 'Arcia', 'SS'),
('Sean', 'Murphy', 'C'),
('Jurickson', 'Profar', 'LF'),
('Mike', 'Yastrzemski', 'OF'),
('Spencer', 'Strider', 'SP'),
('Reynaldo', 'Lopez', 'SP'),
('Chris', 'Sale', 'SP'),
('Max', 'Fried', 'SP'),
('Charlie', 'Morton', 'SP'),
('Raisel', 'Iglesias', 'RP'),
('Joe', 'Jimenez', 'RP'),
('Robert', 'Suarez', 'RP');

-- ============================================================
-- Baltimore Orioles
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Gunnar', 'Henderson', 'SS'),
('Adley', 'Rutschman', 'C'),
('Anthony', 'Santander', 'RF'),
('Ryan', 'Mountcastle', '1B'),
('Jackson', 'Holliday', '2B'),
('Colton', 'Cowser', 'LF'),
('Cedric', 'Mullins', 'CF'),
('Jordan', 'Westburg', '3B'),
('Pete', 'Alonso', 'DH'),
('Samuel', 'Basallo', 'C'),
('Taylor', 'Ward', 'OF'),
('Corbin', 'Burnes', 'SP'),
('Kyle', 'Bradish', 'SP'),
('Chris', 'Bassitt', 'SP'),
('Shane', 'Baz', 'SP'),
('Zach', 'Eflin', 'SP'),
('Ryan', 'Helsley', 'RP'),
('Yennier', 'Cano', 'RP'),
('Craig', 'Kimbrel', 'RP');

-- ============================================================
-- Boston Red Sox
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Rafael', 'Devers', '3B'),
('Jarren', 'Duran', 'CF'),
('Masataka', 'Yoshida', 'DH'),
('Ceddanne', 'Rafaela', 'SS'),
('Triston', 'Casas', '1B'),
('Connor', 'Wong', 'C'),
('Wilyer', 'Abreu', 'RF'),
('Tyler', 'O''Neill', 'LF'),
('Kristian', 'Campbell', '2B'),
('Garrett', 'Crochet', 'SP'),
('Brayan', 'Bello', 'SP'),
('Ranger', 'Suarez', 'SP'),
('Sonny', 'Gray', 'SP'),
('Johan', 'Oviedo', 'SP'),
('Kenley', 'Jansen', 'RP'),
('Justin', 'Slaten', 'RP'),
('Liam', 'Hendriks', 'RP');

-- ============================================================
-- Chicago Cubs
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Alex', 'Bregman', '3B'),
('Dansby', 'Swanson', 'SS'),
('Ian', 'Happ', 'LF'),
('Seiya', 'Suzuki', 'RF'),
('Pete', 'Crow-Armstrong', 'CF'),
('Michael', 'Busch', '1B'),
('Nico', 'Hoerner', '2B'),
('Miguel', 'Amaya', 'C'),
('Matt', 'Shaw', 'DH'),
('Moises', 'Ballesteros', 'DH'),
('Shota', 'Imanaga', 'SP'),
('Jameson', 'Taillon', 'SP'),
('Justin', 'Steele', 'SP'),
('Javier', 'Assad', 'SP'),
('Jordan', 'Wicks', 'SP'),
('Hector', 'Neris', 'RP'),
('Porter', 'Hodge', 'RP');

-- ============================================================
-- Chicago White Sox
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Andrew', 'Benintendi', 'LF'),
('Brooks', 'Baldwin', 'SS'),
('Curtis', 'Mead', '3B'),
('Andrew', 'Vaughn', '1B'),
('Austin', 'Hays', 'RF'),
('Colson', 'Montgomery', 'SS'),
('Lenyn', 'Sosa', '2B'),
('Korey', 'Lee', 'C'),
('Garrett', 'Crochet', 'SP'),
('Drew', 'Thorpe', 'SP'),
('Mike', 'Clevinger', 'SP'),
('Nick', 'Nastrini', 'SP'),
('Michael', 'Kopech', 'RP');

-- ============================================================
-- Cincinnati Reds
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Elly', 'De La Cruz', 'SS'),
('Spencer', 'Steer', '1B'),
('Matt', 'McLain', '2B'),
('TJ', 'Friedl', 'CF'),
('Tyler', 'Stephenson', 'C'),
('Noelvi', 'Marte', 'RF'),
('Sal', 'Stewart', 'DH'),
('JJ', 'Bleday', 'LF'),
('Ke''Bryan', 'Hayes', '3B'),
('Hunter', 'Greene', 'SP'),
('Nick', 'Lodolo', 'SP'),
('Andrew', 'Abbott', 'SP'),
('Graham', 'Ashcraft', 'SP'),
('Alexis', 'Diaz', 'RP'),
('Buck', 'Farmer', 'RP');

-- ============================================================
-- Cleveland Guardians
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Jose', 'Ramirez', '3B'),
('Steven', 'Kwan', 'LF'),
('Josh', 'Naylor', '1B'),
('Bo', 'Naylor', 'C'),
('Brayan', 'Rocchio', '2B'),
('Gabriel', 'Arias', 'SS'),
('Chase', 'DeLauter', 'RF'),
('Travis', 'Bazzana', '2B'),
('Jhonkensy', 'Noel', 'DH'),
('Tanner', 'Bibee', 'SP'),
('Logan', 'Allen', 'SP'),
('Gavin', 'Williams', 'SP'),
('Slade', 'Cecconi', 'SP'),
('Cade', 'Smith', 'RP'),
('Hunter', 'Gaddis', 'RP'),
('Emmanuel', 'Clase', 'RP');

-- ============================================================
-- Colorado Rockies
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Ezequiel', 'Tovar', 'SS'),
('Brenton', 'Doyle', 'CF'),
('Hunter', 'Goodman', 'C'),
('Jordan', 'Beck', 'LF'),
('Willi', 'Castro', '3B'),
('Ryan', 'Ritter', '2B'),
('Jake', 'McCarthy', 'RF'),
('Edouard', 'Julien', '1B'),
('Mickey', 'Moniak', 'DH'),
('Kyle', 'Freeland', 'SP'),
('Cal', 'Quantrill', 'SP'),
('Ryan', 'Feltner', 'SP'),
('Tanner', 'Gordon', 'SP'),
('Daniel', 'Bard', 'RP'),
('Justin', 'Lawrence', 'RP');

-- ============================================================
-- Detroit Tigers
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Riley', 'Greene', 'CF'),
('Spencer', 'Torkelson', '1B'),
('Kerry', 'Carpenter', 'LF'),
('Colt', 'Keith', '2B'),
('Javier', 'Baez', 'SS'),
('Dillon', 'Dingler', 'C'),
('Matt', 'Vierling', '3B'),
('Parker', 'Meadows', 'RF'),
('Tarik', 'Skubal', 'SP'),
('Framber', 'Valdez', 'SP'),
('Jack', 'Flaherty', 'SP'),
('Casey', 'Mize', 'SP'),
('Justin', 'Verlander', 'SP'),
('Jason', 'Foley', 'RP'),
('Tyler', 'Holton', 'RP'),
('Will', 'Vest', 'RP');

-- ============================================================
-- Houston Astros
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Yordan', 'Alvarez', 'DH'),
('Alex', 'Bregman', '3B'),
('Kyle', 'Tucker', 'RF'),
('Jose', 'Altuve', '2B'),
('Yainer', 'Diaz', 'C'),
('Jeremy', 'Pena', 'SS'),
('Jake', 'Meyers', 'CF'),
('Jon', 'Singleton', '1B'),
('Mauricio', 'Dubon', 'OF'),
('Cam', 'Smith', 'OF'),
('Hunter', 'Brown', 'SP'),
('Cristian', 'Javier', 'SP'),
('Spencer', 'Arrighetti', 'SP'),
('Tatsuya', 'Imai', 'SP'),
('Lance', 'McCullers Jr', 'SP'),
('Josh', 'Hader', 'RP'),
('Bryan', 'Abreu', 'RP'),
('Bryan', 'King', 'RP');

-- ============================================================
-- Kansas City Royals
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Bobby', 'Witt Jr', 'SS'),
('Salvador', 'Perez', 'C'),
('Vinnie', 'Pasquantino', '1B'),
('Maikel', 'Garcia', '3B'),
('MJ', 'Melendez', 'LF'),
('Michael', 'Massey', '2B'),
('Kyle', 'Isbel', 'CF'),
('Freddy', 'Fermin', 'DH'),
('Drew', 'Waters', 'RF'),
('Carter', 'Jensen', 'C'),
('Cole', 'Ragans', 'SP'),
('Seth', 'Lugo', 'SP'),
('Michael', 'Wacha', 'SP'),
('Kris', 'Bubic', 'SP'),
('Noah', 'Cameron', 'SP'),
('Lucas', 'Erceg', 'RP'),
('James', 'McArthur', 'RP');

-- ============================================================
-- Los Angeles Angels
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Mike', 'Trout', 'CF'),
('Zach', 'Neto', 'SS'),
('Nolan', 'Schanuel', '1B'),
('Jo', 'Adell', 'RF'),
('Josh', 'Lowe', 'LF'),
('Jorge', 'Soler', 'DH'),
('Logan', 'O''Hoppe', 'C'),
('Christian', 'Moore', '2B'),
('Yoan', 'Moncada', '3B'),
('Yusei', 'Kikuchi', 'SP'),
('Jose', 'Soriano', 'SP'),
('Reid', 'Detmers', 'SP'),
('Grayson', 'Rodriguez', 'SP'),
('Alek', 'Manoah', 'SP'),
('Carlos', 'Estevez', 'RP'),
('Robert', 'Stephenson', 'RP');

-- ============================================================
-- Los Angeles Dodgers
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Shohei', 'Ohtani', 'DH'),
('Mookie', 'Betts', '2B'),
('Freddie', 'Freeman', '1B'),
('Teoscar', 'Hernandez', 'RF'),
('Kyle', 'Tucker', 'LF'),
('Will', 'Smith', 'C'),
('Andy', 'Pages', 'OF'),
('Tommy', 'Edman', 'SS'),
('Max', 'Muncy', '3B'),
('Hyeseong', 'Kim', 'SS'),
('Dalton', 'Rushing', 'C'),
('Yoshinobu', 'Yamamoto', 'SP'),
('Tyler', 'Glasnow', 'SP'),
('Blake', 'Snell', 'SP'),
('Roki', 'Sasaki', 'SP'),
('Emmet', 'Sheehan', 'SP'),
('Walker', 'Buehler', 'SP'),
('Edwin', 'Diaz', 'RP'),
('Evan', 'Phillips', 'RP'),
('Alex', 'Vesia', 'RP'),
('Ryan', 'Brasier', 'RP');

-- ============================================================
-- Miami Marlins
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Xavier', 'Edwards', '2B'),
('Otto', 'Lopez', 'SS'),
('Christopher', 'Morel', '1B'),
('Kyle', 'Stowers', 'LF'),
('Griffin', 'Conine', 'RF'),
('Jakob', 'Marsee', 'CF'),
('Agustin', 'Ramirez', 'C'),
('Graham', 'Pauley', '3B'),
('Sandy', 'Alcantara', 'SP'),
('Eury', 'Perez', 'SP'),
('Max', 'Meyer', 'SP'),
('Braxton', 'Garrett', 'SP'),
('Chris', 'Paddack', 'SP'),
('Tanner', 'Scott', 'RP'),
('Andrew', 'Nardi', 'RP');

-- ============================================================
-- Milwaukee Brewers
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Jackson', 'Chourio', 'CF'),
('Christian', 'Yelich', 'DH'),
('Sal', 'Frelick', 'RF'),
('Garrett', 'Mitchell', 'LF'),
('Brice', 'Turang', '2B'),
('Andrew', 'Vaughn', '1B'),
('Luis', 'Rengifo', '3B'),
('Joey', 'Ortiz', 'SS'),
('William', 'Contreras', 'C'),
('Freddy', 'Peralta', 'SP'),
('Colin', 'Rea', 'SP'),
('Aaron', 'Civale', 'SP'),
('Tobias', 'Myers', 'SP'),
('Joel', 'Payamps', 'RP'),
('Devin', 'Williams', 'RP'),
('Trevor', 'Megill', 'RP');

-- ============================================================
-- Minnesota Twins
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Carlos', 'Correa', 'SS'),
('Byron', 'Buxton', 'CF'),
('Matt', 'Wallner', 'RF'),
('Austin', 'Martin', 'LF'),
('Royce', 'Lewis', '3B'),
('Edouard', 'Julien', '1B'),
('Ryan', 'Jeffers', 'C'),
('Brooks', 'Lee', '2B'),
('Joe', 'Ryan', 'SP'),
('Bailey', 'Ober', 'SP'),
('Simeon', 'Woods Richardson', 'SP'),
('Zebby', 'Matthews', 'SP'),
('Taj', 'Bradley', 'SP'),
('Jhoan', 'Duran', 'RP'),
('Griffin', 'Jax', 'RP');

-- ============================================================
-- New York Mets
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Francisco', 'Lindor', 'SS'),
('Juan', 'Soto', 'LF'),
('Pete', 'Alonso', '1B'),
('Brandon', 'Nimmo', 'CF'),
('Mark', 'Vientos', '3B'),
('Bo', 'Bichette', '3B'),
('Marcus', 'Semien', '2B'),
('Luis', 'Robert Jr', 'CF'),
('Jorge', 'Polanco', '1B'),
('Francisco', 'Alvarez', 'C'),
('Luisangel', 'Acuna', 'OF'),
('Kodai', 'Senga', 'SP'),
('Sean', 'Manaea', 'SP'),
('David', 'Peterson', 'SP'),
('Freddy', 'Peralta', 'SP'),
('Clay', 'Holmes', 'SP'),
('Edwin', 'Diaz', 'RP'),
('Jake', 'Diekman', 'RP'),
('Reed', 'Garrett', 'RP');

-- ============================================================
-- New York Yankees
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Aaron', 'Judge', 'RF'),
('Juan', 'Soto', 'LF'),
('Giancarlo', 'Stanton', 'DH'),
('Jazz', 'Chisholm Jr', '3B'),
('Anthony', 'Volpe', 'SS'),
('Gleyber', 'Torres', '2B'),
('Paul', 'Goldschmidt', '1B'),
('Austin', 'Wells', 'C'),
('Cody', 'Bellinger', 'CF'),
('Trent', 'Grisham', 'OF'),
('Jose', 'Caballero', 'SS'),
('Ryan', 'McMahon', '3B'),
('Gerrit', 'Cole', 'SP'),
('Max', 'Fried', 'SP'),
('Carlos', 'Rodon', 'SP'),
('Luis', 'Gil', 'SP'),
('Will', 'Warren', 'SP'),
('David', 'Bednar', 'RP'),
('Jonathan', 'Loaisiga', 'RP'),
('Luke', 'Weaver', 'RP'),
('Tommy', 'Kahnle', 'RP');

-- ============================================================
-- Oakland Athletics
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Nick', 'Kurtz', '1B'),
('Shea', 'Langeliers', 'C'),
('Lawrence', 'Butler', 'LF'),
('Tyler', 'Soderstrom', 'DH'),
('Denzel', 'Clarke', 'CF'),
('Jacob', 'Wilson', 'SS'),
('Zack', 'Gelof', '2B'),
('JJ', 'Bleday', 'RF'),
('Max', 'Schuemann', '3B'),
('Mason', 'Miller', 'RP'),
('JP', 'Sears', 'SP'),
('Joey', 'Estes', 'SP'),
('Mitch', 'Spence', 'SP'),
('Luis', 'Medina', 'SP'),
('Tyler', 'Ferguson', 'RP');

-- ============================================================
-- Philadelphia Phillies
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Bryce', 'Harper', '1B'),
('Trea', 'Turner', 'SS'),
('Kyle', 'Schwarber', 'DH'),
('Alec', 'Bohm', '3B'),
('J.T.', 'Realmuto', 'C'),
('Brandon', 'Marsh', 'LF'),
('Nick', 'Castellanos', 'RF'),
('Adolis', 'Garcia', 'RF'),
('Justin', 'Crawford', 'CF'),
('Bryson', 'Stott', '2B'),
('Zack', 'Wheeler', 'SP'),
('Aaron', 'Nola', 'SP'),
('Cristopher', 'Sanchez', 'SP'),
('Jesus', 'Luzardo', 'SP'),
('Ranger', 'Suarez', 'SP'),
('Jhoan', 'Duran', 'RP'),
('Jose', 'Alvarado', 'RP'),
('Jeff', 'Hoffman', 'RP');

-- ============================================================
-- Pittsburgh Pirates
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Oneil', 'Cruz', 'SS'),
('Bryan', 'Reynolds', 'CF'),
('Ke''Bryan', 'Hayes', '3B'),
('Jack', 'Suwinski', 'LF'),
('Connor', 'Joe', '1B'),
('Brandon', 'Lowe', '2B'),
('Henry', 'Davis', 'C'),
('Ryan', 'O''Hearn', 'DH'),
('Nick', 'Gonzales', 'SS'),
('Paul', 'Skenes', 'SP'),
('Mitch', 'Keller', 'SP'),
('Jared', 'Jones', 'SP'),
('Bubba', 'Chandler', 'SP'),
('Braxton', 'Ashcraft', 'SP'),
('David', 'Bednar', 'RP'),
('Colin', 'Holderman', 'RP'),
('Dennis', 'Santana', 'RP');

-- ============================================================
-- San Diego Padres
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Fernando', 'Tatis Jr', 'RF'),
('Manny', 'Machado', '3B'),
('Xander', 'Bogaerts', 'SS'),
('Ha-Seong', 'Kim', '2B'),
('Jake', 'Cronenworth', '1B'),
('Nick', 'Castellanos', 'DH'),
('Jackson', 'Merrill', 'CF'),
('David', 'Peralta', 'LF'),
('Kyle', 'Higashioka', 'C'),
('Luis', 'Arraez', '1B'),
('Dylan', 'Cease', 'SP'),
('Yu', 'Darvish', 'SP'),
('Joe', 'Musgrove', 'SP'),
('Nick', 'Pivetta', 'SP'),
('Michael', 'King', 'SP'),
('Robert', 'Suarez', 'RP'),
('Jeremiah', 'Estrada', 'RP');

-- ============================================================
-- San Francisco Giants
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Matt', 'Chapman', '3B'),
('Rafael', 'Devers', '1B'),
('Willy', 'Adames', 'SS'),
('Jung Hoo', 'Lee', 'LF'),
('Heliot', 'Ramos', 'RF'),
('Harrison', 'Bader', 'CF'),
('Luis', 'Arraez', '2B'),
('Patrick', 'Bailey', 'C'),
('Michael', 'Conforto', 'DH'),
('Blake', 'Snell', 'SP'),
('Logan', 'Webb', 'SP'),
('Robbie', 'Ray', 'SP'),
('Jordan', 'Hicks', 'SP'),
('Camilo', 'Doval', 'RP'),
('Tyler', 'Rogers', 'RP');

-- ============================================================
-- Seattle Mariners
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Julio', 'Rodriguez', 'CF'),
('Cal', 'Raleigh', 'C'),
('Josh', 'Naylor', '1B'),
('J.P.', 'Crawford', 'SS'),
('Randy', 'Arozarena', 'LF'),
('Cole', 'Young', '2B'),
('Brendan', 'Donovan', '3B'),
('Luke', 'Raley', 'RF'),
('Victor', 'Robles', 'OF'),
('Logan', 'Gilbert', 'SP'),
('George', 'Kirby', 'SP'),
('Bryce', 'Miller', 'SP'),
('Luis', 'Castillo', 'SP'),
('Bryan', 'Woo', 'SP'),
('Andres', 'Munoz', 'RP'),
('Matt', 'Brash', 'RP');

-- ============================================================
-- St. Louis Cardinals
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Masyn', 'Winn', 'SS'),
('Nolan', 'Gorman', '3B'),
('Lars', 'Nootbaar', 'RF'),
('Brendan', 'Donovan', 'OF'),
('Jordan', 'Walker', 'LF'),
('Alec', 'Burleson', '1B'),
('Ivan', 'Herrera', 'C'),
('Dylan', 'Carlson', 'CF'),
('Matthew', 'Liberatore', 'SP'),
('Sonny', 'Gray', 'SP'),
('Miles', 'Mikolas', 'SP'),
('Steven', 'Matz', 'SP'),
('Andre', 'Pallante', 'SP'),
('Ryan', 'Helsley', 'RP'),
('JoJo', 'Romero', 'RP');

-- ============================================================
-- Tampa Bay Rays
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Yandy', 'Diaz', 'DH'),
('Wander', 'Franco', 'SS'),
('Josh', 'Lowe', 'LF'),
('Randy', 'Arozarena', 'RF'),
('Isaac', 'Paredes', '3B'),
('Brandon', 'Lowe', '2B'),
('Curtis', 'Mead', '1B'),
('Jonny', 'DeLuca', 'CF'),
('Ben', 'Rortvedt', 'C'),
('Shane', 'McClanahan', 'SP'),
('Ryan', 'Pepiot', 'SP'),
('Drew', 'Rasmussen', 'SP'),
('Jeffrey', 'Springs', 'SP'),
('Pete', 'Fairbanks', 'RP'),
('Jason', 'Adam', 'RP');

-- ============================================================
-- Texas Rangers
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Corey', 'Seager', 'SS'),
('Marcus', 'Semien', '2B'),
('Wyatt', 'Langford', 'RF'),
('Evan', 'Carter', 'CF'),
('Josh', 'Jung', '3B'),
('Jake', 'Burger', '1B'),
('Josh', 'Smith', 'LF'),
('Danny', 'Jansen', 'C'),
('Brandon', 'Nimmo', 'LF'),
('Nathan', 'Eovaldi', 'SP'),
('Max', 'Scherzer', 'SP'),
('Jon', 'Gray', 'SP'),
('Cody', 'Bradford', 'SP'),
('Andrew', 'Heaney', 'SP'),
('Kirby', 'Yates', 'RP'),
('Jose', 'Leclerc', 'RP'),
('David', 'Robertson', 'RP');

-- ============================================================
-- Toronto Blue Jays
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('Vladimir', 'Guerrero Jr', '1B'),
('George', 'Springer', 'DH'),
('Daulton', 'Varsho', 'CF'),
('Andres', 'Gimenez', 'SS'),
('Alejandro', 'Kirk', 'C'),
('Davis', 'Schneider', '2B'),
('Spencer', 'Horwitz', '3B'),
('Ernie', 'Clement', 'LF'),
('Addison', 'Barger', 'RF'),
('Kevin', 'Gausman', 'SP'),
('Jose', 'Berrios', 'SP'),
('Dylan', 'Cease', 'SP'),
('Shane', 'Bieber', 'SP'),
('Trey', 'Yesavage', 'SP'),
('Jordan', 'Romano', 'RP'),
('Yimi', 'Garcia', 'RP');

-- ============================================================
-- Washington Nationals
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position) VALUES
('CJ', 'Abrams', 'SS'),
('James', 'Wood', 'LF'),
('Dylan', 'Crews', 'RF'),
('Brady', 'House', '3B'),
('Joey', 'Meneses', '1B'),
('Jacob', 'Young', 'CF'),
('Keibert', 'Ruiz', 'C'),
('Luis', 'Garcia Jr', '2B'),
('Lane', 'Thomas', 'OF'),
('Cade', 'Cavalli', 'SP'),
('MacKenzie', 'Gore', 'SP'),
('Jake', 'Irvin', 'SP'),
('DJ', 'Herz', 'SP'),
('Mitchell', 'Parker', 'SP'),
('Kyle', 'Finnegan', 'RP'),
('Hunter', 'Harvey', 'RP');


-- ============================================================
-- LEGENDS / HALL OF FAMERS / RETIRED GREATS
-- Players who commonly appear on baseball cards
-- ============================================================
INSERT INTO CG_Players (first_name, last_name, primary_position, is_active) VALUES
-- Pre-War / Golden Era
('Babe', 'Ruth', 'RF', 0),
('Lou', 'Gehrig', '1B', 0),
('Ty', 'Cobb', 'CF', 0),
('Honus', 'Wagner', 'SS', 0),
('Walter', 'Johnson', 'SP', 0),
('Christy', 'Mathewson', 'SP', 0),
('Cy', 'Young', 'SP', 0),
('Jimmie', 'Foxx', '1B', 0),
('Rogers', 'Hornsby', '2B', 0),
('Joe', 'DiMaggio', 'CF', 0),
('Ted', 'Williams', 'LF', 0),
('Jackie', 'Robinson', '2B', 0),
('Satchel', 'Paige', 'SP', 0),
('Josh', 'Gibson', 'C', 0),

-- 1950s-1960s
('Mickey', 'Mantle', 'CF', 0),
('Willie', 'Mays', 'CF', 0),
('Hank', 'Aaron', 'RF', 0),
('Roberto', 'Clemente', 'RF', 0),
('Sandy', 'Koufax', 'SP', 0),
('Bob', 'Gibson', 'SP', 0),
('Ernie', 'Banks', 'SS', 0),
('Frank', 'Robinson', 'RF', 0),
('Brooks', 'Robinson', '3B', 0),
('Harmon', 'Killebrew', '1B', 0),
('Al', 'Kaline', 'RF', 0),
('Carl', 'Yastrzemski', 'LF', 0),
('Juan', 'Marichal', 'SP', 0),
('Warren', 'Spahn', 'SP', 0),
('Yogi', 'Berra', 'C', 0),
('Whitey', 'Ford', 'SP', 0),

-- 1970s-1980s
('Pete', 'Rose', '2B', 0),
('Johnny', 'Bench', 'C', 0),
('Joe', 'Morgan', '2B', 0),
('Tom', 'Seaver', 'SP', 0),
('Reggie', 'Jackson', 'RF', 0),
('Nolan', 'Ryan', 'SP', 0),
('Mike', 'Schmidt', '3B', 0),
('George', 'Brett', '3B', 0),
('Robin', 'Yount', 'SS', 0),
('Cal', 'Ripken Jr', 'SS', 0),
('Rickey', 'Henderson', 'LF', 0),
('Tony', 'Gwynn', 'RF', 0),
('Wade', 'Boggs', '3B', 0),
('Ryne', 'Sandberg', '2B', 0),
('Ozzie', 'Smith', 'SS', 0),
('Dave', 'Winfield', 'RF', 0),
('Eddie', 'Murray', '1B', 0),
('Dennis', 'Eckersley', 'RP', 0),
('Don', 'Mattingly', '1B', 0),
('Kirby', 'Puckett', 'CF', 0),
('Roger', 'Clemens', 'SP', 0),
('Dwight', 'Gooden', 'SP', 0),
('Darryl', 'Strawberry', 'RF', 0),

-- 1990s-2000s
('Ken', 'Griffey Jr', 'CF', 0),
('Derek', 'Jeter', 'SS', 0),
('Barry', 'Bonds', 'LF', 0),
('Mark', 'McGwire', '1B', 0),
('Sammy', 'Sosa', 'RF', 0),
('Frank', 'Thomas', '1B', 0),
('Greg', 'Maddux', 'SP', 0),
('Randy', 'Johnson', 'SP', 0),
('Pedro', 'Martinez', 'SP', 0),
('Mariano', 'Rivera', 'RP', 0),
('Jeff', 'Bagwell', '1B', 0),
('Craig', 'Biggio', '2B', 0),
('Ivan', 'Rodriguez', 'C', 0),
('Roberto', 'Alomar', '2B', 0),
('Alex', 'Rodriguez', 'SS', 0),
('Chipper', 'Jones', '3B', 0),
('Manny', 'Ramirez', 'LF', 0),
('Vladimir', 'Guerrero', 'RF', 0),
('Jim', 'Thome', '1B', 0),
('John', 'Smoltz', 'SP', 0),
('Tom', 'Glavine', 'SP', 0),
('Ichiro', 'Suzuki', 'RF', 0),
('David', 'Ortiz', 'DH', 0),
('Trevor', 'Hoffman', 'RP', 0),
('Mike', 'Piazza', 'C', 0),
('Albert', 'Pujols', '1B', 0),
('Miguel', 'Cabrera', '1B', 0),
('CC', 'Sabathia', 'SP', 0),
('Roy', 'Halladay', 'SP', 0),
('Justin', 'Verlander', 'SP', 0),

-- Recent Retirees / Active Card Market
('Robinson', 'Cano', '2B', 0),
('Joey', 'Votto', '1B', 0),
('Buster', 'Posey', 'C', 0),
('Madison', 'Bumgarner', 'SP', 0),
('Clayton', 'Kershaw', 'SP', 0),
('Yadier', 'Molina', 'C', 0),
('David', 'Wright', '3B', 0),
('Ryan', 'Howard', '1B', 0),
('Chase', 'Utley', '2B', 0),
('Jimmy', 'Rollins', 'SS', 0),
('Jose', 'Fernandez', 'SP', 0),
('Tim', 'Lincecum', 'SP', 0),
('Dustin', 'Pedroia', '2B', 0),
('Andrew', 'McCutchen', 'CF', 0),
('Troy', 'Tulowitzki', 'SS', 0),
('Matt', 'Kemp', 'CF', 0),
('Prince', 'Fielder', '1B', 0),
('Nelson', 'Cruz', 'DH', 0),
('Adrian', 'Beltre', '3B', 0),
('Jose', 'Bautista', 'RF', 0),
('Josh', 'Hamilton', 'CF', 0),
('Cole', 'Hamels', 'SP', 0),
('Zack', 'Greinke', 'SP', 0),
('Max', 'Scherzer', 'SP', 0),
('Felix', 'Hernandez', 'SP', 0),
('Kris', 'Bryant', '3B', 0),
('Anthony', 'Rizzo', '1B', 0),
('Javier', 'Baez', 'SS', 0);


-- ============================================================
-- PLAYER NICKNAMES
-- ============================================================

-- === Legends / Hall of Famers ===

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Babe' AND last_name = 'Ruth' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Babe');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Bambino');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Sultan of Swat');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Great Bambino');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Lou' AND last_name = 'Gehrig' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Iron Horse');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Ty' AND last_name = 'Cobb' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Georgia Peach');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Honus' AND last_name = 'Wagner' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Flying Dutchman');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Walter' AND last_name = 'Johnson' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Big Train');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Cy' AND last_name = 'Young' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Cyclone');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Jimmie' AND last_name = 'Foxx' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Double X');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Beast');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Joe' AND last_name = 'DiMaggio' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Yankee Clipper');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Joltin'' Joe');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Ted' AND last_name = 'Williams' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Splendid Splinter');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Teddy Ballgame');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Kid');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Jackie' AND last_name = 'Robinson' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Jackie');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, '42');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Mickey' AND last_name = 'Mantle' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Mick');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Commerce Comet');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Willie' AND last_name = 'Mays' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Say Hey Kid');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Say Hey');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Hank' AND last_name = 'Aaron' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Hammerin'' Hank');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Hammer');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Bad Henry');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Roberto' AND last_name = 'Clemente' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Great One');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Arriba');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Sandy' AND last_name = 'Koufax' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Left Arm of God');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Bob' AND last_name = 'Gibson' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Gibby');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Hoot');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Ernie' AND last_name = 'Banks' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Mr. Cub');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Yogi' AND last_name = 'Berra' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Yogi');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Pete' AND last_name = 'Rose' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Charlie Hustle');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Johnny' AND last_name = 'Bench' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Little General');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Reggie' AND last_name = 'Jackson' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Mr. October');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Nolan' AND last_name = 'Ryan' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Ryan Express');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Mike' AND last_name = 'Schmidt' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Schmidty');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'George' AND last_name = 'Brett' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Mullet');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Cal' AND last_name = 'Ripken Jr' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Iron Man');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Rickey' AND last_name = 'Henderson' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Man of Steal');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Tony' AND last_name = 'Gwynn' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Mr. Padre');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Captain Video');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Ozzie' AND last_name = 'Smith' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Wizard');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Wizard of Oz');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Kirby' AND last_name = 'Puckett' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Puck');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Roger' AND last_name = 'Clemens' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Rocket');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Dwight' AND last_name = 'Gooden' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Doc');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Dr. K');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Darryl' AND last_name = 'Strawberry' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Straw');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Don' AND last_name = 'Mattingly' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Donnie Baseball');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Hit Man');

-- === 1990s-2000s Stars ===

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Ken' AND last_name = 'Griffey Jr' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Kid');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Junior');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Derek' AND last_name = 'Jeter' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Captain');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Mr. November');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'DJ');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Barry' AND last_name = 'Bonds' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'BB');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Mark' AND last_name = 'McGwire' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Big Mac');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Sammy' AND last_name = 'Sosa' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Slammin'' Sammy');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Frank' AND last_name = 'Thomas' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Big Hurt');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Greg' AND last_name = 'Maddux' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Mad Dog');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Professor');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Randy' AND last_name = 'Johnson' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Big Unit');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Pedro' AND last_name = 'Martinez' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Pedro');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Petey');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Mariano' AND last_name = 'Rivera' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Mo');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Sandman');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Ivan' AND last_name = 'Rodriguez' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Pudge');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Alex' AND last_name = 'Rodriguez' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'A-Rod');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Chipper' AND last_name = 'Jones' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Chipper');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Larry');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Manny' AND last_name = 'Ramirez' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Manny Being Manny');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Man-Ram');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Vladimir' AND last_name = 'Guerrero' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Vlad');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Vlad the Impaler');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Jim' AND last_name = 'Thome' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Thomer');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Ichiro' AND last_name = 'Suzuki' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Ichiro');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Laser Show');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'David' AND last_name = 'Ortiz' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Big Papi');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Mike' AND last_name = 'Piazza' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Pizza Man');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Albert' AND last_name = 'Pujols' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Machine');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Prince Albert');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'El Hombre');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Miguel' AND last_name = 'Cabrera' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Miggy');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Roy' AND last_name = 'Halladay' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Doc');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Trevor' AND last_name = 'Hoffman' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Hoffy');

-- === Recent Retirees ===

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Clayton' AND last_name = 'Kershaw' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Kersh');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Claw');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Buster' AND last_name = 'Posey' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Buster');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Madison' AND last_name = 'Bumgarner' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'MadBum');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Yadier' AND last_name = 'Molina' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Yadi');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Chase' AND last_name = 'Utley' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Man');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Silver Fox');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Tim' AND last_name = 'Lincecum' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Freak');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Big Time Timmy Jim');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Dustin' AND last_name = 'Pedroia' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Pedey');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Laser Show');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Josh' AND last_name = 'Hamilton' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Natural');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Adrian' AND last_name = 'Beltre' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'El Koji');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Felix' AND last_name = 'Hernandez' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'King Felix');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The King');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Joey' AND last_name = 'Votto' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Joey Bananas');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Prince' AND last_name = 'Fielder' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Prince');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'CC' AND last_name = 'Sabathia' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'CC');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Dennis' AND last_name = 'Eckersley' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Eck');

-- === Active Players Nicknames ===

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Shohei' AND last_name = 'Ohtani' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Shotime');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Sho');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Aaron' AND last_name = 'Judge' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'All Rise');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Judge');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'BAJ');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Mike' AND last_name = 'Trout' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Millville Meteor');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Kiiiiid');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Trouty');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Mookie' AND last_name = 'Betts' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Mookie');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Markus Lynn');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Freddie' AND last_name = 'Freeman' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Freddie');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Ronald' AND last_name = 'Acuna Jr' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'El Abusador');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Juan' AND last_name = 'Soto' AND is_active = 1 LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Childish Bambino');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Juan Soto Shuffle');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Bryce' AND last_name = 'Harper' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Philly Special');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Harp');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Fernando' AND last_name = 'Tatis Jr' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'El Nino');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Bebo');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Francisco' AND last_name = 'Lindor' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Mr. Smile');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Paquito');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Vladimir' AND last_name = 'Guerrero Jr' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Vladdy');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Vlad Jr');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Pete' AND last_name = 'Crow-Armstrong' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'PCA');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Giancarlo' AND last_name = 'Stanton' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Big G');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Dongcarlo');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Jose' AND last_name = 'Ramirez' AND is_active = 1 LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'J-Ram');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'GOAT');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Julio' AND last_name = 'Rodriguez' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'J-Rod');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Bobby' AND last_name = 'Witt Jr' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'BWJ');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Bobby Dazzler');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Corey' AND last_name = 'Seager' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Seags');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Gunnar' AND last_name = 'Henderson' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'G-Money');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Adley' AND last_name = 'Rutschman' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Adley');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Elly' AND last_name = 'De La Cruz' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'EDLC');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Yordan' AND last_name = 'Alvarez' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Yordan the Destroyer');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Air Yordan');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Jose' AND last_name = 'Altuve' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Tuve');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Giant');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Ozzie' AND last_name = 'Albies' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Oz');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Jazz' AND last_name = 'Chisholm Jr' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Jazz');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Wander' AND last_name = 'Franco' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'El Patron');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Salvador' AND last_name = 'Perez' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Salvy');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'El Nino');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Byron' AND last_name = 'Buxton' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Buck');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Oneil' AND last_name = 'Cruz' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Big O');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Paul' AND last_name = 'Skenes' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Skenes Machine');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Corbin' AND last_name = 'Carroll' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'CC');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Tarik' AND last_name = 'Skubal' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Skubie');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Gerrit' AND last_name = 'Cole' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Gerrit');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Cal' AND last_name = 'Raleigh' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Big Dumper');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Jarren' AND last_name = 'Duran' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Duranimal');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Rafael' AND last_name = 'Devers' AND is_active = 1 LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Scoops');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Raffy');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Carita');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Trea' AND last_name = 'Turner' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Trea Bae');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Kyle' AND last_name = 'Schwarber' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Schwarbs');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Schwarbomb');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Marcell' AND last_name = 'Ozuna' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Big Bear');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Matt' AND last_name = 'Olson' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Oly');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Manny' AND last_name = 'Machado' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'El Ministro');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Jackson' AND last_name = 'Holliday' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Jack');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Roki' AND last_name = 'Sasaki' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Roki');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Monster');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Christian' AND last_name = 'Yelich' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Yeli');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Dansby' AND last_name = 'Swanson' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Dans');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Max' AND last_name = 'Scherzer' AND is_active = 0 LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Mad Max');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Justin' AND last_name = 'Verlander' AND is_active = 0 LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'JV');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Verlander');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'George' AND last_name = 'Springer' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Springer Dinger');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Xander' AND last_name = 'Bogaerts' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'X');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Bogey');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Josh' AND last_name = 'Hader' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Haderade');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Mason' AND last_name = 'Miller' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Gas Man');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Emmanuel' AND last_name = 'Clase' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Clase Act');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Devin' AND last_name = 'Williams' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Airbender');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'CJ' AND last_name = 'Abrams' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'CJ');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Jackson' AND last_name = 'Chourio' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'La Makina');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Alex' AND last_name = 'Bregman' AND is_active = 1 LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Breggy');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Seiya' AND last_name = 'Suzuki' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Seiya Later');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Anthony' AND last_name = 'Rizzo' AND is_active = 0 LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Tony');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Old Faithful');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Kris' AND last_name = 'Bryant' AND is_active = 0 LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'KB');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Steven' AND last_name = 'Kwan' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Kwanasaurus Rex');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Sandy' AND last_name = 'Alcantara' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Sandy');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Yoshinobu' AND last_name = 'Yamamoto' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Yoshi');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Tyler' AND last_name = 'Glasnow' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Glas');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'J.T.' AND last_name = 'Realmuto' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'BCIB');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Satchel' AND last_name = 'Paige' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Satchel');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Josh' AND last_name = 'Gibson' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Black Babe Ruth');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Ryne' AND last_name = 'Sandberg' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Ryno');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Wade' AND last_name = 'Boggs' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Chicken Man');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Tom' AND last_name = 'Seaver' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Tom Terrific');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Franchise');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Carl' AND last_name = 'Yastrzemski' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Yaz');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Frank' AND last_name = 'Robinson' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Robby');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Brooks' AND last_name = 'Robinson' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Human Vacuum Cleaner');
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'Mr. Impossible');

SET @pid = (SELECT player_id FROM CG_Players WHERE first_name = 'Robin' AND last_name = 'Yount' LIMIT 1);
INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (@pid, 'The Kid');
