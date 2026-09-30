import os
import sys

sys.path.insert(0, os.path.expanduser('~/.local/lib/python3.10/site-packages'))
sys.path.insert(0, os.path.dirname(__file__))

from app import application
